<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Vocabulary\TermSetBinding;
use Survos\FolioBundle\Entity\Term;
use Survos\FolioBundle\Entity\TermSet;
use Survos\FolioBundle\Model\FolioContext;

final readonly class FolioTermCloudService
{
    /**
     * Whole-folio term frequency, grouped by term set -- the aggregated counterpart to
     * RowTermsResolver (which resolves terms for a single Row). Reads the same item_facet
     * table FolioFtsIndexer::rebuildFacetCounts() populates, where `value` is already the
     * slugged term code for term-set-bound fields, so it joins straight against Term.code.
     *
     * @return array<string, array{setCode: string, label: string, terms: list<array{term: Term, count: int, docs: int, weight: float}>}>
     */
    public function cloud(FolioContext $ctx, int $limitPerSet = 60): array
    {
        $conn = $ctx->em->getConnection();
        if (!$conn->executeQuery(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'item_facet'"
        )->fetchOne()) {
            return [];
        }

        $fieldToSetCode = [];
        foreach (TermSetBinding::fields() as $setCode => $fields) {
            foreach ($fields as $field) {
                $fieldToSetCode[$field] = $setCode;
            }
        }
        if ($fieldToSetCode === []) {
            return [];
        }

        $fields = array_keys($fieldToSetCode);
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $rows = $conn->executeQuery(
            "SELECT field, value, COUNT(*) AS cnt, COUNT(DISTINCT item_rowid) AS docs
             FROM item_facet
             WHERE field IN ($placeholders)
             GROUP BY field, value
             ORDER BY cnt DESC",
            $fields,
        )->fetchAllAssociative();

        // Rows arrive globally sorted by cnt DESC, so each per-set bucket we build below is
        // already in descending-count order -- filtering a sorted stream preserves each
        // subgroup's relative order.
        $bySet = [];
        foreach ($rows as $row) {
            $setCode = $fieldToSetCode[$row['field']] ?? null;
            if ($setCode === null || count($bySet[$setCode] ?? []) >= $limitPerSet) {
                continue;
            }

            $term = $ctx->em->find(Term::class, "{$ctx->folioCode}:{$setCode}:{$row['value']}");
            if (!$term instanceof Term) {
                continue;
            }

            $bySet[$setCode][] = [
                'term' => $term,
                'count' => (int) $row['cnt'],
                'docs' => (int) $row['docs'],
            ];
        }

        $result = [];
        foreach ($bySet as $setCode => $terms) {
            $termSet = $ctx->em->find(TermSet::class, "{$ctx->folioCode}:{$setCode}");
            if (!$termSet instanceof TermSet || !$termSet->enabled || $terms === []) {
                continue;
            }

            $maxCount = max(array_column($terms, 'count'));
            foreach ($terms as &$entry) {
                $entry['weight'] = $maxCount > 0 ? log(1 + $entry['count']) / log(1 + $maxCount) : 0.0;
            }
            unset($entry);

            $result[$setCode] = [
                'setCode' => $setCode,
                'label' => $termSet->label ?: $setCode,
                'terms' => $terms,
            ];
        }

        uasort($result, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $result;
    }
}
