<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Entity\Core;

/**
 * Explicit aggregate calculation: publishers run this offline; directories read recorded summaries.
 *
 * The five fields it counts by are declared on the DTOs (NewspaperDto: issueKind, issueProcessing; DocumentDto:
 * issueId, recordKind, articleTier), so they are read from dto_data and nowhere else. A SQLite folio keeps dto_data
 * as JSON text and a dfolio as VARIANT; each store has its own reader here, and both feed one calculation.
 */
final readonly class PeriodicalCoverageService
{
    public function __construct(private FolioService $folios)
    {
    }

    public function compute(string $folioCode): array
    {
        $db = $this->folios->context($folioCode)->em->getConnection();

        return self::summarize($folioCode, $db->fetchAllAssociative(...), self::sqliteField(...));
    }

    /** Coverage of a SQLite folio. */
    public static function fromFolio(\PDO $db, string $folioCode): array
    {
        return self::summarize($folioCode, self::reader($db), self::sqliteField(...));
    }

    /**
     * Coverage of a dfolio. A VARIANT path reads one sub-column, so this does not load an issue's whole document
     * (an issue's extras alone are about 90 KB).
     */
    public static function fromDfolio(\PDO $db, string $folioCode): array
    {
        return self::summarize($folioCode, self::reader($db),
            static fn (string ...$path): string => 'CAST(dto_data.'.implode('.', array_map(static fn (string $key): string => '"'.$key.'"', $path)).' AS VARCHAR)');
    }

    private static function sqliteField(string ...$path): string
    {
        return "json_extract(dto_data, '$.".implode('.', $path)."')";
    }

    /** @return callable(string, list<mixed>): list<array<string,mixed>> */
    private static function reader(\PDO $db): callable
    {
        return static function (string $sql, array $parameters) use ($db): array {
            $statement = $db->prepare($sql);
            $statement->execute($parameters);

            return $statement->fetchAll(\PDO::FETCH_ASSOC);
        };
    }

    /**
     * @param callable(string, list<mixed>): list<array<string,mixed>> $read
     * @param callable(string...): string                              $field SQL for one value of dto_data, by key path
     */
    private static function summarize(string $folioCode, callable $read, callable $field): array
    {
        $issues = $read(
            'SELECT local_id, label, '.$field('issueProcessing', 'stage').' AS stage FROM item WHERE core_id = ? AND COALESCE('.$field('issueKind').", '') <> 'annual index'",
            [Core::id($folioCode, 'doc')],
        );
        $byIssue = [];
        foreach ($issues as $issue) {
            $byIssue[$issue['local_id']] = ['headlines' => 0, 'articles' => 0, 'ads' => 0, 'blocks' => 0,
                'label' => $issue['label'] ?? $issue['local_id'], 'stage' => $issue['stage']];
        }
        // Headlines and articles are counted apart because they are different products: one is a
        // block the page happened to break that way, the other is a story put back together.
        // Presenting 20,945 of the first as "articles" overstates what the archive actually has.
        $tier = 'COALESCE('.$field('articleTier').", 'full')";
        $groups = $read(
            'SELECT '.$field('issueId').' AS issue_id,
                CASE
                    WHEN '.$field('recordKind')." = 'advertisement' THEN 'ads'
                    WHEN $tier = 'block' THEN 'headlines'
                    ELSE 'articles'
                END AS kind,
                $tier = 'block' AS is_block,
                COUNT(*) AS total FROM item WHERE core_id = ? GROUP BY issue_id, kind, is_block",
            [Core::id($folioCode, 'article')],
        );
        foreach ($groups as $group) {
            if (null === $group['issue_id']) {
                // DocumentDto declares issueId. A folio whose articles keep it only in extras predates that.
                throw new \UnexpectedValueException(sprintf('%d article(s) of %s have no issueId in dto_data. Rebuild this folio.', $group['total'], $folioCode));
            }
            if (isset($byIssue[$group['issue_id']])) {
                $byIssue[$group['issue_id']][$group['kind']] += (int) $group['total'];
                // Blocks cut across kinds: an "ad" on a basic issue is a block like any other.
                $byIssue[$group['issue_id']]['blocks'] += $group['is_block'] ? (int) $group['total'] : 0;
            }
        }
        $stats = ['issues' => count($byIssue), 'headlines' => 0, 'articles' => 0, 'ads' => 0, 'blocks' => 0,
            'issuesWithText' => 0, 'issuesWithAds' => 0];
        foreach ($byIssue as &$issue) {
            foreach (['headlines', 'articles', 'ads', 'blocks'] as $kind) {
                $stats[$kind] += $issue[$kind];
            }
            // Every block carries OCR text, so a basic issue whose blocks were all guessed to be ads
            // still has text.
            $text = $issue['headlines'] + $issue['articles'] + $issue['blocks'];
            $stats['issuesWithText'] += (int) ($text > 0);
            $stats['issuesWithAds'] += (int) ($issue['ads'] > 0);
            $issue['status'] = match (true) {
                'segmented' === $issue['stage'] => 'Extraction run finished · review needed',
                $issue['articles'] > 0 => $issue['ads'] > 0 ? 'Articles and ads available' : 'Articles available · no ads extracted',
                $issue['blocks'] > 0 => 'Blocks available · not yet stitched into articles',
                $text > 0 => 'Text available',
                default => 'Awaiting extraction',
            };
        }
        unset($issue);

        return $stats + ['byIssue' => $byIssue];
    }
}
