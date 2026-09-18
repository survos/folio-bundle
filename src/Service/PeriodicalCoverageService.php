<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Entity\Core;

/** Explicit aggregate calculation: publishers run this offline; directories read recorded summaries. */
final readonly class PeriodicalCoverageService
{
    public function __construct(private FolioService $folios) {}

    public function compute(string $folioCode): array
    {
        $connection = $this->folios->context($folioCode)->em->getConnection();
        $issues = $connection->fetchAllAssociative(
            "SELECT local_id, label, json_extract(extras, '$.issueProcessing.stage') AS stage FROM item WHERE core_id = ? AND COALESCE(json_extract(extras, '$.issueKind'), '') <> 'annual index'",
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
        $groups = $connection->fetchAllAssociative(
            "SELECT json_extract(extras, '$.issueId') AS issue_id,
                CASE
                    WHEN json_extract(extras, '$.recordKind') = 'advertisement' THEN 'ads'
                    WHEN COALESCE(json_extract(extras, '$.articleTier'), 'full') = 'block' THEN 'headlines'
                    ELSE 'articles'
                END AS kind,
                COALESCE(json_extract(extras, '$.articleTier'), 'full') = 'block' AS is_block,
                COUNT(*) AS total FROM item WHERE core_id = ? GROUP BY issue_id, kind, is_block",
            [Core::id($folioCode, 'article')],
        );
        foreach ($groups as $group) {
            if (isset($byIssue[$group['issue_id']])) {
                $byIssue[$group['issue_id']][$group['kind']] += (int) $group['total'];
                // Blocks cut across kinds: an "ad" on a basic issue is a block like any other.
                $byIssue[$group['issue_id']]['blocks'] += $group['is_block'] ? (int) $group['total'] : 0;
            }
        }
        $stats = ['issues' => count($byIssue), 'headlines' => 0, 'articles' => 0, 'ads' => 0, 'blocks' => 0,
            'issuesWithText' => 0, 'issuesWithAds' => 0];
        foreach ($byIssue as &$issue) {
            foreach (['headlines', 'articles', 'ads', 'blocks'] as $kind) { $stats[$kind] += $issue[$kind]; }
            // Every block carries OCR text, so a basic issue whose blocks were all guessed to be ads
            // still has text.
            $text = $issue['headlines'] + $issue['articles'] + $issue['blocks'];
            $stats['issuesWithText'] += (int) ($text > 0);
            $stats['issuesWithAds'] += (int) ($issue['ads'] > 0);
            $issue['status'] = match (true) {
                $issue['stage'] === 'segmented' => 'Extraction run finished · review needed',
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
