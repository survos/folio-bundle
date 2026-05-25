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
        $prompts = [];
        foreach ($this->topDtoLabels($dtoChoices) as $label) {
            $prompts[] = sprintf('Build a gallery of notable %s. Cite why each row belongs.', $label);
        }

        $terms = $this->promptTerms($ctx);
        foreach (array_chunk($terms, 3) as $chunk) {
            if (count($chunk) >= 2) {
                $prompts[] = sprintf('Build a gallery around %s. Cite the connecting theme.', implode(', ', $chunk));
            }
        }

        foreach ($this->audiencePrompts($ctx) as $prompt) {
            $prompts[] = $prompt;
        }

        $prompts[] = 'Actors gallery: theater, stage, performance, portraits. Cite rows.';
        $prompts[] = 'Athletes gallery: sport, teams, competition, movement. Cite rows.';
        $prompts[] = 'Soldiers gallery: military, uniforms, memorials, service. Cite rows.';
        $prompts[] = 'Kids gallery: school, children, games, family. Cite rows.';

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
            'actors' => ['theater', 'stage', 'performance', 'actor'],
            'athletes' => ['sport', 'baseball', 'football', 'team'],
            'politicians' => ['mayor', 'senator', 'political', 'government'],
            'artists' => ['art', 'artist', 'painting', 'photograph'],
            'kids' => ['school', 'children', 'toy', 'family'],
            'geeks' => ['science', 'technology', 'machine', 'computer'],
            'soldiers' => ['military', 'soldier', 'uniform', 'memorial'],
        ];

        $pdo = $this->connect($ctx->path);
        if (!$this->hasFtsIndex($pdo)) {
            return [];
        }

        $prompts = [];
        foreach ($topics as $audience => $terms) {
            if ($this->hasMatches($pdo, $terms)) {
                $prompts[] = sprintf('%s gallery: %s. Cite rows.', ucfirst($audience), implode(', ', $terms));
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
