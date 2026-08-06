<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Vocabulary\TermSetBinding;
use Survos\FolioBundle\Entity\Term;
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
        $byField = [];
        $totalValues = [];
        foreach ($rows as $row) {
            $field = (string) $row['field'];
            if (!isset($fieldMeta[$field])) {
                continue;
            }
            $totalValues[$field] = ($totalValues[$field] ?? 0) + 1;

            if (count($byField[$field] ?? []) >= $limitPerField) {
                continue;
            }

            $setCode = $fieldToSetCode[$field] ?? null;
            if ($setCode !== null) {
                $term = $ctx->em->find(Term::class, "{$ctx->folioCode}:{$setCode}:{$row['value']}");
                if (!$term instanceof Term) {
                    // An unclassified value under a controlled set isn't real vocabulary yet --
                    // skip rather than show a raw code where a translated label is expected.
                    continue;
                }
                $label = $term->label ?: $term->code;
                $code = $term->code;
            } else {
                $label = (string) $row['value'];
                $code = null;
            }

            $byField[$field][] = [
                'label' => $label,
                'code' => $code,
                'count' => (int) $row['cnt'],
                'docs' => (int) $row['docs'],
            ];
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
