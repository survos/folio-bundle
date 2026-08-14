<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Search;

use Doctrine\DBAL\Connection;
use Mezcalito\UxSearchBundle\Attribute\AsSearch;
use Mezcalito\UxSearchBundle\Search\AbstractSearch;
use Mezcalito\UxSearchBundle\Twig\Components\Facet\RangeSlider;
use Mezcalito\UxSearchBundle\Twig\Components\Facet\RefinementList;
use Survos\FolioBundle\Service\FolioFacetFieldResolver;
use Survos\FolioBundle\Service\FolioService;
use Survos\SearchBundle\Search\HitTemplateSearchInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsSearch(index: 'folio_row', name: 'folio_row', adapter: 'folio_fts')]
final class FolioRowSearch extends AbstractSearch implements HitTemplateSearchInterface
{
    private ?string $hitTemplate = null;

    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioFacetFieldResolver $facetFieldResolver,
        private readonly TranslatorInterface $translator,
        /** survos_folio.yaml's search_title_sort_enabled — off for collections where titles are
         *  sparse/generic and year is the meaningful ordering (e.g. openfoto's photo archives). */
        private readonly bool $titleSortEnabled = true,
        /** survos_folio.yaml's search_default_sort — must match one of the sort keys actually
         *  added below (e.g. 'year:asc') or it's silently ignored and the first-added sort wins,
         *  same as AbstractSearch's own default-sort behavior. */
        private readonly ?string $defaultSort = null,
    ) {
    }

    public function build(array $options = []): void
    {
        // addFacet()/addAvailableSort() (below and in AbstractSearch) APPEND rather than
        // replace, on the assumption that build() only ever runs once per instance. That's
        // false whenever this shared/singleton search handles more than one folioCode
        // within the same PHP process — worker-mode request reuse, or two search widgets
        // on one page — so facets from a PRIOR build() leak into this one, and adapters
        // relying on getFacets() (e.g. computing facet distributions) can end up out of
        // sync with what the template expects, throwing "Facet distribution ... is not
        // found". reset() (from AbstractSearch/ResetInterface) clears exactly that
        // accumulating state; call it first so every build() starts from a clean slate.
        $this->reset();

        $this->hitTemplate = isset($options['hitTemplate']) && is_string($options['hitTemplate']) ? $options['hitTemplate'] : null;

        $folioCode = isset($options['folioCode']) && is_string($options['folioCode']) ? $options['folioCode'] : null;
        if ($folioCode === null || !str_contains($folioCode, '/')) {
            throw new \InvalidArgumentException('Folio row search requires a folioCode option like "dc/05747667f".');
        }

        $ctx = $this->folios->context($folioCode);
        [$provider, $dataset] = explode('/', $folioCode, 2);
        $connection = $ctx->em->getConnection();

        $selectedCore = isset($options['coreCode']) && is_string($options['coreCode']) && $options['coreCode'] !== '' ? $options['coreCode'] : null;
        $selectedDtoType = isset($options['dtoType']) && is_string($options['dtoType']) && $options['dtoType'] !== '' ? $options['dtoType'] : null;

        $where = [];
        $params = [];
        if ($selectedCore !== null) {
            $where[] = 'd.core_id = :coreId';
            $params['coreId'] = $folioCode . ':' . $selectedCore;
        }
        if ($selectedDtoType !== null) {
            $where[] = 'd.dto_type = :dtoType';
            $params['dtoType'] = $selectedDtoType;
        }

        $this->setAvailableHitsPerPage([12, 24, 48]);
        // A facet with exactly one distinct value can't filter anything (every row already has
        // it) -- e.g. a search scoped to the 'obj' core has nothing but dtoType=photograph, so
        // that facet would just be an inert checkbox. Same check the dynamic schema_property
        // facets below already get via hasUsableFacetValues(); core/dtoType just aren't part of
        // that loop (they're not schema_property rows), so they need it applied explicitly.
        if ($selectedCore === null && $this->hasMultipleDistinctValues($connection, "substr(d.core_id, instr(d.core_id, ':') + 1)", $where, $params)) {
            $this->addFacet('core', $this->translator->trans('facet.core', domain: 'system'), RefinementList::class);
        }
        if ($selectedDtoType === null && $this->hasMultipleDistinctValues($connection, 'd.dto_type', $where, $params)) {
            $this->addFacet('dtoType', $this->translator->trans('facet.type', domain: 'system'), RefinementList::class);
        }
        $facetColumns = [
            'core' => "substr(d.core_id, instr(d.core_id, ':') + 1)",
            'dtoType' => 'd.dto_type',
        ];

        foreach ($this->facetFieldResolver->facetFieldNames($connection, coreCode: $selectedCore) as $field) {
            if (isset($facetColumns[$field['name']])) {
                continue;
            }

            // Integer/float fields (e.g. year) render as a range slider; everything else is a
            // refinement list. The field's schema type is the single source of truth — no per-field config.
            $isNumeric = (bool) preg_match('/\b(int|integer|float|double|number|numeric)\b/i', $field['type']);
            $this->addFacet($field['name'], $this->translatedFacetLabel($field['name'], $field['label']), $isNumeric ? RangeSlider::class : RefinementList::class);
            $facetColumns[$field['name']] = $this->jsonExtract($field['name']);
        }

        $sorts = [];
        if ($this->titleSortEnabled) {
            $sorts['label:asc'] = 'Title A-Z';
            $sorts['label:desc'] = 'Title Z-A';
        }
        // Sort by year (the integer), not the fuzzy date string ("ca. 1920" sorts lexicographically
        // wrong) — but only when the dataset actually has integer years. Many datasets carry only a
        // free-text `date` (never normalised to `year`); offering a no-op "Year" sort there is misleading.
        // Deliberately jsonExtract('year'), NOT d.sort_key: sort_key is a VIRTUAL generated column
        // (uncomputed/unstored) covered only by the composite (core_id, sort_key, local_id) index,
        // so a core-less existence check against it can't use that index at all -- would need its
        // own plain single-column index for no real benefit over reusing the one below.
        // json_extract(dto_data,'$.year') is fast here because FolioArchiveService::inflate()
        // builds idx_item_json_year as a PARTIAL index (rows WHERE ... IS NOT NULL) -- empty (so
        // this query is instant) for a dataset that genuinely has no years, unlike a plain index
        // which still holds one entry per NULL row. Measured live on wikibase/enslaved's 1.2M
        // rows: ~2.4s before the partial index, ~5ms after.
        if ($this->hasNonNullExpression($connection, $this->jsonExtract('year'))) {
            $sorts['year:asc'] = 'Year Old-New';
            $sorts['year:desc'] = 'Year New-Old';
        }
        // AbstractSearch::search() picks current($this->availableSorts) as the default -- whichever
        // sort was added FIRST -- so move the configured default to the front of the list rather
        // than needing a separate "set default" API. Silently falls back to natural order if the
        // configured key isn't in $sorts (e.g. defaultSort=year:asc but this dataset has no years).
        if ($this->defaultSort !== null && isset($sorts[$this->defaultSort])) {
            $sorts = [$this->defaultSort => $sorts[$this->defaultSort]] + $sorts;
        }
        foreach ($sorts as $key => $label) {
            $this->addAvailableSort($key, $label);
        }
        $this->enableUrlRewriting();

        $this->setAdapterParameters([
            'table' => 'item',
            'ftsTable' => 'item_fts',
            'joinExpression' => 'f.rowid = d.rowid',
            'selectColumns' => [
                'd.id',
                $this->literal($provider) . ' AS provider',
                $this->literal($dataset) . ' AS dataset',
                "substr(d.core_id, instr(d.core_id, ':') + 1) AS coreCode",
                'd.local_id AS localId',
                'd.label',
                'd.dto_type AS dtoType',
                "json_extract(d.dto_data, '$.citationUrl') AS citationUrl",
                "json_extract(d.dto_data, '$.description') AS description",
                "json_extract(d.dto_data, '$.\"ai:denseSummary\"') AS denseSummary",
                "json_extract(d.dto_data, '$.iiifBase') AS iiifBase",
                "json_extract(d.dto_data, '$.thumbnailUrl') AS thumbnailUrl",
                "json_extract(d.dto_data, '$.largeImageUrl') AS largeImageUrl",
                "json_extract(d.dto_data, '$.pageCount') AS pageCount",
                "json_extract(d.dto_data, '$.imageCount') AS imageCount",
                "json_extract(d.dto_data, '$.itemCount') AS itemCount",
                "json_extract(d.dto_data, '$.sourceFormat') AS sourceFormat",
                // Materialized column, not jsonExtract('year') -- see
                // FolioFtsIndexer::ensurePrimarySortColumn(); this is the exact 'year:asc' sort
                // used as the default below.
                'd.sort_key AS year',
                "json_extract(d.dto_data, '$.city') AS city",
                "json_extract(d.dto_data, '$.state') AS state",
                "json_extract(d.dto_data, '$.country') AS country",
            ],
            'facetColumns' => $facetColumns,
            'sortColumns' => [
                'label' => 'd.label',
                'year' => 'd.sort_key',
            ],
            'where' => $where === [] ? null : implode(' AND ', $where),
            'params' => $params,
            // 50 was too low: real facet vocabularies run into the thousands of distinct values
            // (mus/fpus has 12,224 distinct tags alone), so anything past the ~50 most common
            // silently never reached the client at all -- e.g. "african american" (275 photos,
            // ranked 97th by count) was invisible in both the default list AND the facet search
            // box, since that box only filters values already fetched from the server, not a
            // fresh query (2026-08-04, found via a demo for a Black-history-focused partner).
            'maxFacetValues' => 1000,
            // item_facet_count is partitioned by core (core='' = all cores), so the precomputed fast
            // path serves core-scoped pages too — the adapter scopes counts to the active core. Only a
            // pinned dtoType (an extra where-constraint the precompute can't represent) forces the live
            // aggregation. Requires folios rebuilt with the core-partitioned schema.
            'facetCountTable' => $selectedDtoType === null ? 'item_facet_count' : null,
            'facetValueTable' => 'item_facet',
        ]);
    }

    public function getHitTemplate(): ?string
    {
        return $this->hitTemplate;
    }

    private function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Whether any row carries a non-null value for a real column/expression — used to gate sorts
     * (e.g. only offer the Year sort when the dataset actually has integer years).
     * `WHERE expr IS NOT NULL LIMIT 1` looks like the obvious query but SQLite's planner does NOT
     * reliably use an index for it (confirmed live: `idx_item_sort_key` present and actually usable
     * -- 7ms under an explicit INDEXED BY hint -- yet the planner still chose a full table SCAN
     * without one, on SQLite 3.45.1, even after ANALYZE). `ORDER BY expr LIMIT 1` is the form that
     * gets the index chosen naturally: finding the first row in index order is the exact query an
     * index is for, so the planner uses it without hinting. Needs a genuine index over $expression
     * (not e.g. a composite index with something else as the leading column).
     */
    private function hasNonNullExpression(Connection $connection, string $expression): bool
    {
        return (bool) $connection->executeQuery(
            sprintf('SELECT 1 FROM item d WHERE %s IS NOT NULL ORDER BY %s LIMIT 1', $expression, $expression),
        )->fetchOne();
    }

    /**
     * A facet whose only distinct value is shared by every matching row can't filter anything --
     * e.g. dtoType when a search is scoped to a single-dtoType core. Runs against the current
     * $where/$params (already reflects any OTHER pinned facet, e.g. a selected core when checking
     * dtoType) so it answers "does this actually vary within what's being searched right now",
     * not "does it vary across the whole dataset". core/dtoType aren't schema_property rows, so
     * they can't reuse hasUsableFacetValues()'s item_facet_count lookup.
     *
     * @param list<string> $where
     * @param array<string, scalar> $params
     */
    private function hasMultipleDistinctValues(Connection $connection, string $expression, array $where, array $params): bool
    {
        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);

        return (int) $connection->executeQuery(
            sprintf('SELECT COUNT(DISTINCT %s) FROM item d WHERE %s', $expression, $whereSql),
            $params,
        )->fetchOne() > 1;
    }

    private function jsonExtract(string $field): string
    {
        return sprintf("json_extract(d.dto_data, '$.%s')", str_replace("'", "''", $field));
    }

    /**
     * Term-set-coded schema fields (cul, med, dept, pla, ...) already have a localized
     * header via the existing 'term.<name>' keys in the 'system' domain (the same keys
     * folio/detail.html.twig uses for term-set labels) — prefer that over the raw,
     * merely-humanized schema_property.label so the facet panel is actually localizable,
     * not just the three hardcoded facet.* headers.
     */
    private function translatedFacetLabel(string $name, string $fallback): string
    {
        $key = 'term.' . $name;
        $label = $this->translator->trans($key, domain: 'system');

        return $label !== $key ? $label : $fallback;
    }
}
