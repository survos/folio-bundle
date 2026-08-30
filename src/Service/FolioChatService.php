<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Gcf\Generic\Encoder;
use Psr\Log\LoggerInterface;
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
        private ?LoggerInterface $logger = null,
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
     * @param array<string, mixed> $contextSections
     */
    public function askScoped(FolioContext $ctx, string $question, string $scope, array $contextSections, ?string $conversationId = null): FolioChatResult
    {
        $question = trim($question);
        $contextPrompt = $this->scopedContextPrompt($question, $scope, $contextSections);
        if ($question === '') {
            return new FolioChatResult('', '', $contextPrompt, [], []);
        }

        if ($this->agent === null) {
            return new FolioChatResult(
                question: $question,
                answer: 'The Scholar agent is not configured, but the source context for this scope is shown on this page.',
                contextPrompt: $contextPrompt,
                hits: [],
                cards: [],
            );
        }

        $this->holder->set($ctx);
        try {
            $result = $this->agent->call(
                new MessageBag(
                    Message::forSystem($this->scopedSystemPrompt()),
                    Message::ofUser($contextPrompt),
                ),
            );
        } catch (\Throwable $e) {
            $this->logger?->error('Scoped folio Scholar call failed.', [
                'exception' => $e,
                'folio' => $ctx->folioCode,
                'scope' => $scope,
                'conversationId' => $conversationId,
            ]);

            return new FolioChatResult(
                question: $question,
                answer: $this->scopedFailureAnswer($e),
                contextPrompt: $contextPrompt,
                hits: [],
                cards: [],
                error: sprintf('%s: %s', $e::class, $e->getMessage()),
            );
        } finally {
            $this->holder->set(null);
        }

        $answer = $result->getContent();
        if (!\is_string($answer) || trim($answer) === '') {
            $answer = 'I could not produce a useful Scholar response from the available context.';
        }

        $chatResult = new FolioChatResult(
            question: $question,
            answer: trim($answer),
            contextPrompt: $contextPrompt,
            hits: [],
            cards: [],
        );

        if ($conversationId !== null && $this->dispatcher !== null) {
            $this->dispatcher->dispatch(new FolioChatTurnEvent($ctx, $conversationId, $chatResult));
        }

        return $chatResult;
    }

    /**
     * @param array<string, mixed> $contextSections
     * @param callable(string): void $onText
     */
    public function streamScoped(FolioContext $ctx, string $question, string $scope, array $contextSections, callable $onText, ?string $conversationId = null): FolioChatResult
    {
        $question = trim($question);
        $contextPrompt = $this->scopedContextPrompt($question, $scope, $contextSections);
        if ($question === '') {
            return new FolioChatResult('', '', $contextPrompt, [], []);
        }

        if ($this->agent === null) {
            $answer = 'The Scholar agent is not configured, but the source context for this scope is shown on this page.';
            $onText($answer);

            return new FolioChatResult($question, $answer, $contextPrompt, [], []);
        }

        $answer = '';
        $this->holder->set($ctx);
        try {
            $result = $this->agent->call(
                new MessageBag(
                    Message::forSystem($this->scopedSystemPrompt()),
                    Message::ofUser($contextPrompt),
                ),
                ['stream' => true],
            );

            // 0.13: the agent hands back a lazy Execution, never a StreamResult, so the old
            // `instanceof StreamResult` gate never matched and streaming fell through to the
            // buffered branch. asTextStream() yields the TextDeltas directly; it throws if the
            // "stream" option above ever goes away, which the catch below already reports.
            foreach ($result->asTextStream() as $delta) {
                $text = $delta->getText();
                if ($text === '') {
                    continue;
                }

                $answer .= $text;
                $onText($text);
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Streaming scoped folio Scholar call failed.', [
                'exception' => $e,
                'folio' => $ctx->folioCode,
                'scope' => $scope,
                'conversationId' => $conversationId,
            ]);

            $answer = $this->scopedFailureAnswer($e);
            $onText($answer);

            return new FolioChatResult(
                question: $question,
                answer: $answer,
                contextPrompt: $contextPrompt,
                hits: [],
                cards: [],
                error: sprintf('%s: %s', $e::class, $e->getMessage()),
            );
        } finally {
            $this->holder->set(null);
        }

        if (trim($answer) === '') {
            $answer = 'I could not produce a useful Scholar response from the available context.';
            $onText($answer);
        }

        $chatResult = new FolioChatResult(
            question: $question,
            answer: trim($answer),
            contextPrompt: $contextPrompt,
            hits: [],
            cards: [],
        );

        if ($conversationId !== null && $this->dispatcher !== null) {
            $this->dispatcher->dispatch(new FolioChatTurnEvent($ctx, $conversationId, $chatResult));
        }

        return $chatResult;
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
                ],
            );
            $answer = $result->getContent();
        } catch (\Throwable $e) {
            $this->logger?->error('Folio Scholar collection call failed.', [
                'exception' => $e,
                'folio' => $ctx->folioCode,
                'conversationId' => $conversationId,
            ]);

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
        if ($hits === []) {
            return trim('Question: ' . $question . "\n\nCandidate items from the collection: none found.");
        }

        // denseSummary (curated) is missing for most rows today, and the row's own description()
        // isn't always present either — but the FTS5 match snippet always is (it's derived from
        // the match itself), so it's included as its own column rather than only as a fallback.
        // GCF's tabular form declares the field union once and marks genuinely-absent fields with
        // ~ per row, instead of the description line being silently skipped for rows that lack one.
        $rows = array_map(static function (FolioChatHit $hit): array {
            $row = [
                'localId' => $hit->localId,
                'title' => $hit->label ?: '(untitled)',
            ];
            $summary = $hit->denseSummary() ?? $hit->description();
            if ($summary !== null) {
                $row['description'] = $summary;
            }
            if ($hit->snippet !== '') {
                $row['snippet'] = $hit->snippet;
            }

            return $row;
        }, $hits);

        return trim(sprintf(
            "Question: %s\n\nCandidate items from the collection, GCF format (tabular; ~ marks a field not available for that item):\n%s",
            $question,
            Encoder::encode($rows),
        ));
    }

    /**
     * @param array<string, mixed> $contextSections
     */
    private function scopedContextPrompt(string $question, string $scope, array $contextSections): string
    {
        $lines = [
            'Question: ' . ($question !== '' ? $question : '(no question yet)'),
            'Scope: ' . $scope,
            '',
            'Available source context:',
        ];

        foreach ($contextSections as $label => $value) {
            $body = $this->contextValueToText($value);
            if ($body === '') {
                continue;
            }
            $lines[] = '';
            $lines[] = '## ' . $label;
            $lines[] = $body;
        }

        return trim(implode("\n", $lines));
    }

    private function contextValueToText(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (\is_scalar($value)) {
            return trim((string) $value);
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return \is_string($json) ? trim($json) : '';
    }

    private function scopedSystemPrompt(): string
    {
        return <<<'PROMPT'
            You are The Scholar, an expert guide to one archival object or one page of an archival object.

            Use the provided source context first: catalog metadata, source fields, page metadata, OCR text,
            dense summaries, layout, ledger data, and existing enrichment. If OCR or enrichment is missing,
            say what is missing instead of pretending it exists.

            You may add concise historical knowledge that helps interpret the object or page, but clearly
            distinguish it from the provided source data. Do not invent catalog facts, names, dates, or text
            that are not in the source context. Use markdown for formatting.
            PROMPT;
    }

    private function scopedFailureAnswer(\Throwable $e): string
    {
        if (
            $e instanceof \Symfony\AI\Platform\Exception\InvalidArgumentException
            && str_contains($e->getMessage(), 'API key must not be empty')
        ) {
            return 'The Scholar agent is configured, but its API key is empty. Set `OPENAI_API_KEY` in your local environment and try again.';
        }

        return 'I could not complete the Scholar response right now. The source context for this scope is shown on this page.';
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You are an expert, friendly guide to ONE digital collection. The user's message gives a question
            and a list of candidate items from the collection, in GCF format: a header line declares the field
            names once (localId, title, and optionally description and snippet), then one row per item with
            values separated by |. A ~ in a cell means that field has no value for that item, not that it's
            empty text. "snippet" is the exact matched text from the search index — useful even when
            description is absent.

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
