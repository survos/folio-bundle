<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Model\FolioContext;

final class FolioChatPromptSuggester
{
    public function __construct(
        private readonly FolioWordCloudService $wordCloud,
    ) {}

    /**
     * @param array<string, list<array{type: string|null, label: string, count: int}>> $dtoChoices
     * @return list<string>
     */
    public function suggest(FolioContext $ctx, array $dtoChoices, int $limit = 8): array
    {
        // Natural, inviting questions a visitor would actually ask — grounded in what this
        // collection contains (its dominant item types, salient terms, and matching topics).
        $prompts = ['Give me an overview of this collection — what is it about?'];

        foreach ($this->topDtoLabels($dtoChoices) as $label) {
            $prompts[] = sprintf('What kinds of %s are here, and which stand out?', $label);
        }

        $terms = $this->promptTerms($ctx);
        foreach (array_chunk($terms, 2) as $chunk) {
            if (count($chunk) === 2) {
                $prompts[] = sprintf('What does this collection show about %s and %s?', $chunk[0], $chunk[1]);
            }
        }

        foreach ($this->audiencePrompts($ctx) as $prompt) {
            $prompts[] = $prompt;
        }

        $prompts[] = 'What time period and places does this collection cover?';
        $prompts[] = 'Pick a few standout items and tell me their stories.';

        return array_slice(array_values(array_unique($prompts)), 0, $limit);
    }

    /**
     * @return list<string>
     */
    private function promptTerms(FolioContext $ctx): array
    {
        $terms = [];
        foreach ($this->wordCloud->cloud($ctx, 40) as $term) {
            $text = $term['text'];
            if (!is_string($text) || preg_match('/^\d+$/', $text)) {
                continue;
            }
            $terms[] = $text;
            if (count($terms) >= 9) {
                break;
            }
        }

        return $terms;
    }

    /**
     * @param array<string, list<array{type: string|null, label: string, count: int}>> $dtoChoices
     * @return list<string>
     */
    private function topDtoLabels(array $dtoChoices): array
    {
        $all = [];
        foreach ($dtoChoices as $choices) {
            foreach ($choices as $choice) {
                if ($choice['type'] !== null && $choice['count'] > 0) {
                    $all[] = $choice;
                }
            }
        }

        usort($all, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_values(array_map(
            static fn (array $choice): string => strtolower($choice['label']),
            array_slice($all, 0, 3),
        ));
    }

    /**
     * @return list<string>
     */
    private function audiencePrompts(FolioContext $ctx): array
    {
        $topics = [
            'theater and performers' => ['theater', 'stage', 'performance', 'actor'],
            'sports and athletes' => ['sport', 'baseball', 'football', 'team'],
            'politics and government' => ['mayor', 'senator', 'political', 'government'],
            'art and artists' => ['art', 'artist', 'painting', 'photograph'],
            'schools and children' => ['school', 'children', 'toy', 'family'],
            'science and technology' => ['science', 'technology', 'machine', 'computer'],
            'military service' => ['military', 'soldier', 'uniform', 'memorial'],
        ];

        $pdo = $this->connect($ctx->path);
        if (!$this->hasFtsIndex($pdo)) {
            return [];
        }

        $prompts = [];
        foreach ($topics as $topic => $terms) {
            if ($this->hasMatches($pdo, $terms)) {
                $prompts[] = sprintf('What can I find here about %s?', $topic);
            }
        }

        return $prompts;
    }

    /**
     * @param list<string> $terms
     */
    private function hasMatches(\PDO $pdo, array $terms): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM item_fts WHERE item_fts MATCH :query LIMIT 1');
        $stmt->execute([
            'query' => implode(' OR ', array_map(static fn (string $term): string => $term . '*', $terms)),
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function connect(string $dbFile): \PDO
    {
        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function hasFtsIndex(\PDO $pdo): bool
    {
        return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'item_fts'")->fetchColumn() === 'item_fts';
    }
}
