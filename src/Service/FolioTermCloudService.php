<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Vocabulary\TermSetBinding;
use Survos\FolioBundle\Model\FolioContext;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class FolioTermCloudService
{
    public function __construct(
        private FolioFacetFieldResolver $facetFields,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Whole-folio term frequency for EVERY facet field the search sidebar would show (not just
     * term-set-bound ones) -- reuses FolioFacetFieldResolver so this dropdown never drifts out of
     * sync with what FolioRowSearch actually exposes as a refinement list. A field with a real
     * Term entity behind it (subject/epoch/poi/...) gets translated labels via that Term; a plain
     * facet (tags/city/country/donor/...) shows its raw string value -- the point being to make
     * that contrast visible: controlled vocabulary renders clean, free text renders messy.
     *
     * @return array<string, array{fieldName: string, label: string, setCode: ?string, totalValues: int, terms: list<array{label: string, code: ?string, count: int, docs: int, weight: float}>}>
     */
    public function cloud(FolioContext $ctx, int $limitPerField = 60): array
    {
        $conn = $ctx->em->getConnection();
        if (!$conn->executeQuery(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'item_facet'"
        )->fetchOne()) {
            return [];
        }

        $fieldMeta = [];
        foreach ($this->facetFields->refinementFieldNames($conn) as $field) {
            $fieldMeta[$field['name']] = $field;
        }
        if ($fieldMeta === []) {
            return [];
        }

        $fieldToSetCode = [];
        foreach (TermSetBinding::fields() as $setCode => $boundFields) {
            foreach ($boundFields as $boundField) {
                $fieldToSetCode[$boundField] = $setCode;
            }
        }

        $fieldNames = array_keys($fieldMeta);
        $placeholders = implode(', ', array_fill(0, count($fieldNames), '?'));
        $rows = $conn->executeQuery(
            "SELECT field, value, COUNT(*) AS cnt, COUNT(DISTINCT item_rowid) AS docs
             FROM item_facet
             WHERE field IN ($placeholders)
             GROUP BY field, value
             ORDER BY cnt DESC",
            $fieldNames,
        )->fetchAllAssociative();

        // Rows arrive globally sorted by cnt DESC, so each per-field bucket built below is already
        // in descending-count order -- filtering a sorted stream preserves each subgroup's order.
        // $totalValues counts EVERY distinct value per field, even past $limitPerField -- a free-text
        // field like tags can carry thousands of one-off values, and silently showing only the top
        // 60 with no indication of that would understate exactly the messiness this cloud exists to
        // show (2026-08-05, caught via mus/fpus: tags looked like "only 60" when it's really 6,112).
        //
        // Two passes: first collect the (already capped-to-60-per-field) candidate rows and every
        // term-set id they need, then resolve all those terms in ONE batched query. This used to
        // call $em->find(Term::class, $id) per row -- up to (term-set fields) x 60 individual
        // round trips (~900-1000 seen live on a single search page), each a distinct id so no
        // per-id cache could help.
        $candidates = [];
        $neededIds = [];
        $totalValues = [];
        foreach ($rows as $row) {
            $field = (string) $row['field'];
            if (!isset($fieldMeta[$field])) {
                continue;
            }
            $totalValues[$field] = ($totalValues[$field] ?? 0) + 1;

            if (count($candidates[$field] ?? []) >= $limitPerField) {
                continue;
            }

            $setCode = $fieldToSetCode[$field] ?? null;
            $termId = $setCode !== null ? "{$ctx->folioCode}:{$setCode}:{$row['value']}" : null;
            if ($termId !== null) {
                $neededIds[$termId] = true;
            }

            $candidates[$field][] = [
                'termId' => $termId,
                'value' => (string) $row['value'],
                'count' => (int) $row['cnt'],
                'docs' => (int) $row['docs'],
            ];
        }

        $terms = [];
        if ($neededIds !== []) {
            $ids = array_keys($neededIds);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            foreach ($conn->executeQuery(
                "SELECT id, label, code FROM term WHERE id IN ($placeholders)",
                $ids,
            )->fetchAllAssociative() as $termRow) {
                $terms[(string) $termRow['id']] = $termRow;
            }
        }

        $byField = [];
        foreach ($candidates as $field => $entries) {
            foreach ($entries as $entry) {
                if ($entry['termId'] !== null) {
                    $termRow = $terms[$entry['termId']] ?? null;
                    if ($termRow === null) {
                        // An unclassified value under a controlled set isn't real vocabulary yet --
                        // skip rather than show a raw code where a translated label is expected.
                        continue;
                    }
                    $label = $termRow['label'] !== null && $termRow['label'] !== '' ? (string) $termRow['label'] : (string) $termRow['code'];
                    $code = (string) $termRow['code'];
                } else {
                    $label = $entry['value'];
                    $code = null;
                }

                $byField[$field][] = [
                    'label' => $label,
                    'code' => $code,
                    'count' => $entry['count'],
                    'docs' => $entry['docs'],
                ];
            }
        }

        $result = [];
        foreach ($byField as $field => $entries) {
            if ($entries === []) {
                continue;
            }

            $maxCount = max(array_column($entries, 'count'));
            foreach ($entries as &$entry) {
                $entry['weight'] = $maxCount > 0 ? log(1 + $entry['count']) / log(1 + $maxCount) : 0.0;
            }
            unset($entry);

            $result[$field] = [
                'fieldName' => $field,
                'label' => $this->facetFields->translatedLabel($this->translator, $field, $fieldMeta[$field]['label']),
                'setCode' => $fieldToSetCode[$field] ?? null,
                'totalValues' => $totalValues[$field] ?? count($entries),
                'terms' => $entries,
            ];
        }

        uasort($result, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $result;
    }
}
