<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Event\FolioChatTurnEvent;
use Survos\FolioBundle\Model\FolioChatAnswer;
use Survos\FolioBundle\Model\FolioChatCard;
use Survos\FolioBundle\Model\FolioChatHit;
use Survos\FolioBundle\Model\FolioChatResult;
use Survos\FolioBundle\Model\FolioContext;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class FolioChatService
{
    public function __construct(
        private FolioRetriever $retriever,
        private FolioChatContextHolder $holder,
        private ?AgentInterface $agent = null,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {}

    public function ask(FolioContext $ctx, string $question, ?string $coreCode = null, ?string $dtoType = null, int $limit = 12, ?string $conversationId = null): FolioChatResult
    {
        $question = trim($question);
        if ($question === '') {
            return new FolioChatResult('', '', '', [], []);
        }

        $hits = $this->retriever->retrieve($ctx, $question, $limit, $coreCode, $dtoType);
        [$answer, $cards] = $this->compose($ctx, $question, $hits, $conversationId);

        $result = new FolioChatResult(
            question: $question,
            answer: $answer,
            contextPrompt: $this->contextPrompt($question, $hits),
            hits: $hits,
            cards: $cards,
        );

        if ($conversationId !== null && $this->dispatcher !== null) {
            $this->dispatcher->dispatch(new FolioChatTurnEvent($ctx, $conversationId, $result));
        }

        return $result;
    }

    /**
     * Ask the model for a structured response — a short synthesis plus one caption per cited source —
     * and pair each cited source back to its row, so the UI can show the AI's own narration next to
     * the row's photo (with the database record as the supporting footnote).
     *
     * @param list<FolioChatHit> $hits
     * @return array{0: string, 1: list<FolioChatCard>}
     */
    private function compose(FolioContext $ctx, string $question, array $hits, ?string $conversationId): array
    {
        if ($this->agent === null || $hits === []) {
            return [$this->extractiveAnswer($hits), $this->fallbackCards($hits)];
        }

        // Structured output: the platform constrains the model to the FolioChatAnswer JSON schema
        // (summary + items[{localId, caption}]) — no "return JSON" instructions in the prompt, no
        // brittle parsing. The holder lets the agent's tools search this same folio mid-call. On any
        // failure we degrade to the extractive answer + uncaptioned cards.
        $this->holder->set($ctx);
        try {
            $result = $this->agent->call(
                new MessageBag(
                    Message::forSystem($this->systemPrompt()),
                    Message::ofUser($this->contextPrompt($question, $hits)),
                ),
                [
                    'response_format' => FolioChatAnswer::class,
                    'folio_chat' => [
                        'conversation_id' => $conversationId,
                        'folio_code' => $ctx->folioCode,
                    ],
                ],
            );
            $answer = $result->getContent();
        } catch (\Throwable) {
            return [$this->extractiveAnswer($hits), $this->fallbackCards($hits)];
        } finally {
            $this->holder->set(null);
        }

        if (!$answer instanceof FolioChatAnswer) {
            return [$this->extractiveAnswer($hits), $this->fallbackCards($hits)];
        }

        $byId = [];
        foreach ($hits as $hit) {
            $byId[$hit->localId] = $hit;
        }

        $cards = [];
        foreach ($answer->items as $item) {
            $localId = trim($item->localId);
            if ($localId === '') {
                continue;
            }
            // Seed hit if present, else fetch it — the model may cite an item it found via a tool
            // search rather than from the seed list.
            $hit = $byId[$localId] ?? $this->retriever->byLocalId($ctx, $localId);
            if ($hit instanceof FolioChatHit) {
                $caption = trim($item->caption);
                $cards[] = new FolioChatCard($hit, $caption !== '' ? $caption : null);
            }
        }

        $summary = trim($answer->summary);

        // Model cites specific items → captioned cards. Cites none but search found rows → those as
        // uncaptioned "backup" (the summary explains). Only an empty search yields no cards.
        return [
            $summary !== '' ? $summary : $this->extractiveAnswer($hits),
            $cards !== [] ? $cards : $this->fallbackCards($hits),
        ];
    }

    /**
     * Photo cards with no AI caption — used when there is no agent or the model output is unusable,
     * so the user still gets the matched rows (image + record) instead of nothing.
     *
     * @param list<FolioChatHit> $hits
     * @return list<FolioChatCard>
     */
    private function fallbackCards(array $hits): array
    {
        return array_map(
            static fn (FolioChatHit $hit): FolioChatCard => new FolioChatCard($hit),
            array_slice($hits, 0, 8),
        );
    }

    /**
     * @param list<FolioChatHit> $hits
     */
    private function extractiveAnswer(array $hits): string
    {
        if ($hits === []) {
            return 'No matching rows were found in this folio.';
        }

        $labels = array_values(array_filter(array_map(
            static fn (FolioChatHit $hit): ?string => $hit->label,
            array_slice($hits, 0, 3),
        )));

        if ($labels === []) {
            return sprintf('I found %d matching rows. Review the cited sources below.', count($hits));
        }

        return sprintf(
            'I found %d matching rows. The strongest matches are %s.',
            count($hits),
            implode('; ', $labels),
        );
    }

    /**
     * @param list<FolioChatHit> $hits
     */
    private function contextPrompt(string $question, array $hits): string
    {
        $lines = ['Question: ' . $question, '', 'Candidate items from the collection:'];

        foreach ($hits as $hit) {
            // denseSummary (curated) if present, else the row's own description.
            $summary = $hit->denseSummary() ?? $hit->description();
            $lines[] = '';
            $lines[] = '- localId: ' . $hit->localId;
            $lines[] = '  title: ' . ($hit->label ?: '(untitled)');
            if ($summary !== null) {
                $lines[] = '  description: ' . $summary;
            }
        }

        return trim(implode("\n", $lines));
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You are an expert, friendly guide to ONE digital collection. The user's message gives a question
            and a list of candidate items from the collection — each with a localId, a title, and a description.

            The candidates are a starting point, not the whole collection. When they don't fully answer the
            question — a different angle, a comparison, or more examples — call search_collection with your own
            query to find more items, and call get_object(localId) when you need an item's full details before
            describing it. Prefer a tool search over guessing.

            Answer by synthesizing across the items, not by listing them. In "summary", give a direct, specific
            1-3 sentence answer to the question. In "items", include only the items that genuinely help answer
            it, most relevant first: copy each item's localId exactly, and write a 1-2 sentence caption —
            grounded only in that item's title/description — saying what it is and why it answers the question.
            Use only the provided items; never invent. If none answer the question, return an empty items list
            and say so plainly in the summary.
            PROMPT;
    }
}
