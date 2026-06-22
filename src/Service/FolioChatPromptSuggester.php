<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Model\FolioContext;
use Symfony\Component\String\Inflector\EnglishInflector;

/**
 * Suggested chat prompts, grounded in what the folio actually holds.
 *
 * Every prompt is built from the folio's own facet values (item_facet_count) — the content types it
 * holds, its top places, and its top keywords — never from stemmed word-cloud tokens. Templates:
 *   - "Show me {keyword} {type}s"        e.g. "Show me civil war documents"
 *   - "How many {type} are from {place}?"  e.g. "How many photographs are from Boston?"
 *   - "Show me {type} from {place}"
 *   - "What kinds of {type} are here, and which stand out?"
 */
final class FolioChatPromptSuggester
{
    /** Facet fields that name a place, in preference order (human-readable first, ISO country last). */
    private const PLACE_FIELDS = [
        'city', 'place', 'locality', 'county', 'state', 'region',
        'subjectsGeographic', 'location', 'country',
    ];

    /** Facet fields that carry topical keywords / subjects. */
    private const KEYWORD_FIELDS = [
        'subjects', 'subjectsTopical', 'keywords', 'tags', 'topic', 'genreSpecific',
    ];

    /** ISO2 → readable name, so a country facet doesn't read as "from US". */
    private const COUNTRY_NAMES = [
        'US' => 'the United States', 'GB' => 'the United Kingdom', 'AU' => 'Australia',
        'HU' => 'Hungary', 'NZ' => 'New Zealand', 'DK' => 'Denmark', 'AT' => 'Austria',
        'DE' => 'Germany', 'FR' => 'France', 'UY' => 'Uruguay', 'SU' => 'the USSR',
    ];

    private EnglishInflector $inflector;

    public function __construct()
    {
        $this->inflector = new EnglishInflector();
    }

    /**
     * @param array<string, list<array{type: string|null, label: string, count: int}>> $dtoChoices
     * @return list<string>
     */
    public function suggest(FolioContext $ctx, array $dtoChoices, int $limit = 8): array
    {
        $pdo = $this->connect($ctx->path);

        $types = $this->topTypeLabels($dtoChoices);            // ['photograph','document',...]
        $primary = $types[0] ?? 'item';
        $places = $this->topFacetValues($pdo, self::PLACE_FIELDS, 4);
        // Keywords exclude the type words themselves (so a photo folio doesn't suggest
        // "photograph photographs"); for the folio-of-folios this keeps map/document as keywords.
        $keywords = $this->topFacetValues($pdo, self::KEYWORD_FIELDS, 6, $types);

        $prompts = ['Give me an overview — what is this collection about?'];

        // Keyword × type — "Show me civil war documents", "Show me map collections".
        foreach (array_slice($keywords, 0, 3) as $keyword) {
            $prompts[] = sprintf('Show me %s %s.', $keyword, $this->plural($primary));
        }

        // Type × place — alternate the two phrasings for variety.
        foreach (array_values($places) as $i => $place) {
            $prompts[] = $i % 2 === 0
                ? sprintf('How many %s are from %s?', $this->plural($primary), $place)
                : sprintf('Show me %s from %s.', $this->plural($primary), $place);
        }

        // Type overview — the one good prompt from before.
        foreach (array_slice($types, 0, 2) as $type) {
            $prompts[] = sprintf('What kinds of %s are here, and which stand out?', $this->plural($type));
        }

        $prompts[] = 'What time period and places does this cover?';

        return array_slice(array_values(array_unique($prompts)), 0, $limit);
    }

    /**
     * Top values across a set of facet fields, most frequent first, de-duplicated case-insensitively.
     *
     * @param list<string> $fields
     * @param list<string> $exclude  values to drop (case-insensitive), e.g. the content-type labels
     * @return list<string>
     */
    private function topFacetValues(\PDO $pdo, array $fields, int $limit, array $exclude = []): array
    {
        if (!$this->hasTable($pdo, 'item_facet_count') || $fields === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($fields), '?'));
        $stmt = $pdo->prepare(
            "SELECT field, value, total FROM item_facet_count
             WHERE field IN ($placeholders) AND value IS NOT NULL AND value != ''
             ORDER BY total DESC",
        );
        $stmt->execute($fields);

        // Preserve the field preference order (city before country) as a tiebreaker on equal-ish counts.
        $rank = array_flip($fields);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        usort($rows, static fn (array $a, array $b): int =>
            ($b['total'] <=> $a['total']) ?: (($rank[$a['field']] ?? 99) <=> ($rank[$b['field']] ?? 99)));

        $excludeLower = array_map('strtolower', $exclude);
        $seen = [];
        $values = [];
        foreach ($rows as $row) {
            $value = $this->readableValue((string) $row['field'], (string) $row['value']);
            $key = strtolower($value);
            if ($value === '' || mb_strlen($value) > 40 || preg_match('/^\d+$/', $value)
                || in_array($key, $excludeLower, true) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $values[] = $value;
            if (count($values) >= $limit) {
                break;
            }
        }

        return $values;
    }

    private function readableValue(string $field, string $value): string
    {
        if ($field === 'country' && isset(self::COUNTRY_NAMES[$value])) {
            return self::COUNTRY_NAMES[$value];
        }

        return $value;
    }

    /**
     * @param array<string, list<array{type: string|null, label: string, count: int}>> $dtoChoices
     * @return list<string>
     */
    private function topTypeLabels(array $dtoChoices): array
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

    private function plural(string $word): string
    {
        return $this->inflector->pluralize($word)[0] ?? $word;
    }

    private function connect(string $dbFile): \PDO
    {
        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function hasTable(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :t");
        $stmt->execute(['t' => $table]);

        return $stmt->fetchColumn() === $table;
    }
}
