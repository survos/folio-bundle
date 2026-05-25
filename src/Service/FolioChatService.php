<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Model\FolioChatHit;
use Survos\FolioBundle\Model\FolioChatResult;
use Survos\FolioBundle\Model\FolioContext;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

final readonly class FolioChatService
{
    public function __construct(
        private FolioRetriever $retriever,
        private ?AgentInterface $agent = null,
    ) {}

    public function ask(FolioContext $ctx, string $question, ?string $coreCode = null, ?string $dtoType = null, int $limit = 12): FolioChatResult
    {
        $question = trim($question);
        if ($question === '') {
            return new FolioChatResult('', '', '', []);
        }

        $hits = $this->retriever->retrieve($ctx, $question, $limit, $coreCode, $dtoType);

        return new FolioChatResult(
            question: $question,
            answer: $this->answer($question, $hits),
            contextPrompt: $this->contextPrompt($question, $hits),
            hits: $hits,
        );
    }

    /**
     * @param list<FolioChatHit> $hits
     */
    private function answer(string $question, array $hits): string
    {
        if ($this->agent === null || $hits === []) {
            return $this->extractiveAnswer($hits);
        }

        $result = $this->agent->call(new MessageBag(
            Message::forSystem($this->systemPrompt()),
            Message::ofUser($this->contextPrompt($question, $hits)),
        ));

        return $this->stringResult($result->getContent()) ?: $this->extractiveAnswer($hits);
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
        $lines = [
            'Answer the question using only the cited folio rows.',
            'If the sources do not contain enough evidence, say so.',
            '',
            'Question: ' . $question,
            '',
            'Sources:',
        ];

        foreach ($hits as $index => $hit) {
            $summary = $hit->denseSummary();
            $lines[] = sprintf(
                '[%d] %s / %s / %s: %s',
                $index + 1,
                $hit->coreCode,
                $hit->dtoType ?: 'row',
                $hit->localId,
                $hit->label ?: '(untitled)',
            );
            if ($summary) {
                $lines[] = 'Summary: ' . $summary;
            }
            if ($hit->snippet !== '') {
                $lines[] = 'Snippet: ' . str_replace(['[[[', ']]]'], '', $hit->snippet);
            }
            if ($hit->citationUrl()) {
                $lines[] = 'Citation: ' . $hit->citationUrl();
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    private function systemPrompt(): string
    {
        return 'You answer questions about one portable museum folio. Use only the cited folio rows in the user message. Cite source numbers like [1] and [2]. If the sources do not answer the question, say that the folio rows shown do not contain enough evidence.';
    }

    private function stringResult(mixed $content): ?string
    {
        if (is_string($content) && trim($content) !== '') {
            return trim($content);
        }
        if ($content instanceof \Stringable) {
            $value = trim((string) $content);
            return $value !== '' ? $value : null;
        }

        return null;
    }
}
