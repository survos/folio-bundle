<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Search;

use Doctrine\DBAL\Connection;
use Mezcalito\UxSearchBundle\Attribute\AsSearch;
use Mezcalito\UxSearchBundle\Search\AbstractSearch;
use Mezcalito\UxSearchBundle\Twig\Components\Facet\RangeSlider;
use Mezcalito\UxSearchBundle\Twig\Components\Facet\RefinementList;
use Survos\FolioBundle\Service\FolioService;
use Survos\SearchBundle\Search\HitTemplateSearchInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsSearch(index: 'folio_row', name: 'folio_row', adapter: 'folio_fts')]
final class FolioRowSearch extends AbstractSearch implements HitTemplateSearchInterface
{
    private ?string $hitTemplate = null;

    public function __construct(
        private readonly FolioService $folios,
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

        foreach ($this->folioFacetFields($connection) as $field) {
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
        if ($this->hasColumnValues($connection, 'year')) {
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
     * @return list<array{name: string, label: string}>
     */
    private function folioFacetFields(Connection $connection): array
    {
        if (!$this->tableExists($connection, 'schema_property')) {
            return [];
        }

        $rows = $connection->executeQuery(<<<'SQL'
            SELECT name, label, type, filterable, facet
            FROM schema_property
            WHERE visible = 1
            ORDER BY
                CASE WHEN facet = 1 OR filterable = 1 THEN 0 ELSE 1 END,
                position,
                name
        SQL)->fetchAllAssociative();

        // schema_property carries many duplicate rows per field (one per observed row/dtoType), often
        // with inconsistent type/flags. Dedup by name so the 8-facet cap counts *distinct* fields —
        // otherwise duplicates burn the budget before every term-set facet (dept, med, cul, …) surfaces.
        // A name only counts once it actually passes shouldExposeFolioFacet, so a json-typed duplicate
        // that's rejected doesn't block a later array/string duplicate of the same field from passing.
        $facets = [];
        $seen = [];
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            if (isset($seen[$name]) || !$this->shouldExposeFolioFacet($connection, $name, (string) $row['type'], (bool) $row['filterable'], (bool) $row['facet'])) {
                continue;
            }
            $seen[$name] = true;

            $facets[] = [
                'name' => $name,
                'label' => $this->humanize($row['label'] !== null && $row['label'] !== '' ? (string) $row['label'] : $name),
                'type' => (string) $row['type'],
            ];

            if (count($facets) >= 8) {
                break;
            }
        }

        return $facets;
    }

    private function shouldExposeFolioFacet(Connection $connection, string $name, string $type, bool $filterable, bool $facet): bool
    {
        if ($this->isNeverFacet($name) || !$this->isFacetType($type)) {
            return false;
        }

        if (!$this->hasUsableFacetValues($connection, $name)) {
            return false;
        }

        if ($facet || $filterable) {
            return true;
        }

        return in_array($name, [
            'country',
            'city',
            'date',
            'language',
            // Rights/licence is a high-value facet. Cleveland carries it as free text (not a URI), so
            // expose both the canonical `rights`/`license` and the URI form.
            'rights',
            'rightsUri',
            'license',
            'contentType',
            'itemType',
            'medium',
            'format',
            'collection',
            // Genre/form terms (MODS genre) — flagged a facet on the DTO; surface when values exist.
            'genreBasic',
            'genreSpecific',
            'creator',
            'repository',
        ], true);
    }

    private function isFacetType(string $type): bool
    {
        return $type !== 'json';
    }

    private function isNeverFacet(string $name): bool
    {
        $blocked = [
            'id',
            'localId',
            'sourceId',
            'title',
            'label',
            'description',
            'hasImages',
            'hasTranscription',
            'imageCount',
            'itemCount',
            'pageCount',
            'dctermsDescription',
            'dctermsDate',
            'iiifBase',
            'thumbnailUrl',
            'largeImageUrl',
            'citationUrl',
            'sourceUrl',
            'url',
            'image',
            'latitude',
            'longitude',
            'ai:denseSummary',
            // External-resource / authority links are reference data, not facets (e.g. a wikidata Q-id
            // per object yields ~1 value per row — useless as a refinement and noisy in the sidebar).
            'externalResources',
            'wikidata',
        ];

        $lowerName = strtolower($name);

        return in_array($name, $blocked, true)
            || str_ends_with($lowerName, 'url')
            || str_ends_with($lowerName, 'id')
            || str_contains($lowerName, 'wikidata')
            || str_contains($lowerName, 'latitude')
            || str_contains($lowerName, 'longitude');
    }

    /**
     * Whether any row carries a non-null value for a dto_data field — used to gate sorts (e.g. only
     * offer the Year sort when the dataset actually has integer years). LIMIT 1 stops at the first hit,
     * so it's cheap when the field exists; a full miss scans the core but only runs once per build.
     */
    private function hasColumnValues(Connection $connection, string $field): bool
    {
        return (bool) $connection->executeQuery(
            sprintf('SELECT 1 FROM item d WHERE %s IS NOT NULL LIMIT 1', $this->jsonExtract($field)),
        )->fetchOne();
    }

    private function hasUsableFacetValues(Connection $connection, string $field): bool
    {
        if (!$this->tableExists($connection, 'item_facet_count')) {
            return true;
        }

        return (int) $connection->executeQuery(
            'SELECT COUNT(*) FROM item_facet_count WHERE field = :field',
            ['field' => $field],
        )->fetchOne() > 1;
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

    private function tableExists(Connection $connection, string $table): bool
    {
        return $connection->executeQuery(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table",
            ['table' => $table],
        )->fetchOne() === $table;
    }

    private function humanize(string $field): string
    {
        $label = preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace(['_', ':'], ' ', $field)) ?? $field;

        return str_replace([' Uri', ' Url'], [' URI', ' URL'], ucwords($label));
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
