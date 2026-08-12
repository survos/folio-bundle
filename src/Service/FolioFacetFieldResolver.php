<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The exact field-selection Survos\FolioBundle\Search\FolioRowSearch uses to decide what appears
 * as a facet in the search sidebar (schema_property row -> visible/facet/filterable/blocklist/
 * 8-cap) -- extracted so other consumers (FolioTermCloudService's term-cloud dropdown) show
 * precisely the same fields the sidebar does, not an approximation that can silently drift out of
 * sync as the sidebar's own selection logic evolves.
 */
final class FolioFacetFieldResolver
{
    private const MAX_FACETS = 8;

    // Keyed by spl_object_id($connection) . ':' . field -- this resolver is invoked once per
    // rendered item on pages that list many rows (e.g. the "Most Results" facet-value panel),
    // and schema_property/item_facet_count never change mid-request, so re-querying per item
    // was pure waste (seen live: 320+ identical `item_facet_count` COUNT queries for one field
    // on a single search page). tableExists() and hasUsableFacetValues() share this cache.
    /** @var array<string, bool> */
    private array $tableExistsCache = [];

    /** @var array<string, bool> */
    private array $usableFacetValuesCache = [];

    /** @var array<int, list<array{name: string, label: string, type: string}>> */
    private array $facetFieldNamesCache = [];

    private const NEVER_FACET = [
        'id', 'localId', 'sourceId', 'title', 'label', 'description', 'hasImages', 'hasTranscription',
        'imageCount', 'itemCount', 'pageCount', 'dctermsDescription', 'dctermsDate', 'iiifBase',
        'thumbnailUrl', 'largeImageUrl', 'citationUrl', 'sourceUrl', 'url', 'image', 'latitude', 'longitude',
        'ai:denseSummary', 'externalResources', 'wikidata',
    ];

    private const FALLBACK_FACETABLE = [
        'country', 'city', 'date', 'language', 'rights', 'rightsUri', 'license', 'contentType', 'itemType',
        'medium', 'format', 'collection', 'genreBasic', 'genreSpecific', 'creator', 'repository',
    ];

    /**
     * @return list<array{name: string, label: string, type: string}>
     */
    public function facetFieldNames(Connection $connection, ?int $limit = self::MAX_FACETS): array
    {
        // This resolver runs once per rendered item on pages that list many rows (the "Most
        // Results" facet-value panel, in particular) -- schema_property/item_facet_count are
        // immutable for the lifetime of the request, so re-deriving the field list per item was
        // pure N+1 waste. Cache by connection identity + limit.
        $cacheKey = spl_object_id($connection) . ':' . ($limit ?? 'null');
        if (isset($this->facetFieldNamesCache[$cacheKey])) {
            return $this->facetFieldNamesCache[$cacheKey];
        }

        if (!$this->tableExists($connection, 'schema_property')) {
            return $this->facetFieldNamesCache[$cacheKey] = [];
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
        // with inconsistent type/flags. Dedup by name so the cap counts *distinct* fields -- otherwise
        // duplicates burn the budget before every term-set facet (dept, med, cul, ...) surfaces.
        // Mark $seen BEFORE calling shouldExpose (not just on success) -- a field that legitimately
        // fails (e.g. no usable facet values) must still be skipped on its remaining duplicate rows,
        // or every one of them re-triggers shouldExpose()'s DB query for nothing.
        $facets = [];
        $seen = [];
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            if (!$this->shouldExpose($connection, $name, (string) $row['type'], (bool) $row['filterable'], (bool) $row['facet'])) {
                continue;
            }

            $facets[] = [
                'name' => $name,
                'label' => $this->humanize($row['label'] !== null && $row['label'] !== '' ? (string) $row['label'] : $name),
                'type' => (string) $row['type'],
            ];

            if ($limit !== null && count($facets) >= $limit) {
                break;
            }
        }

        return $this->facetFieldNamesCache[$cacheKey] = $facets;
    }

    /**
     * The subset of facetFieldNames() that renders as a RefinementList in the sidebar (discrete
     * values), excluding numeric fields that render as a RangeSlider instead (e.g. year) --
     * useful for anything that visualizes discrete facet VALUES rather than a numeric range
     * (term clouds, a term-cloud navbar menu).
     *
     * @return list<array{name: string, label: string, type: string}>
     */
    public function refinementFieldNames(Connection $connection, ?int $limit = self::MAX_FACETS): array
    {
        return array_values(array_filter(
            $this->facetFieldNames($connection, $limit),
            static fn (array $field): bool => !preg_match('/\b(int|integer|float|double|number|numeric)\b/i', $field['type']),
        ));
    }

    /**
     * Term-set-coded schema fields (cul, med, dept, pla, ...) already have a localized header via
     * the existing 'term.<name>' keys in the 'system' domain (the same keys folio/detail.html.twig
     * and FolioRowSearch::translatedFacetLabel() use for term-set labels) -- shared here so every
     * consumer of this resolver's field list (term cloud page, term-cloud navbar menu) shows the
     * SAME label for a given field, not a diverging humanized fallback in one of them.
     */
    public function translatedLabel(TranslatorInterface $translator, string $field, string $fallback): string
    {
        $key = 'term.' . $field;
        $label = $translator->trans($key, domain: 'system');

        return $label !== $key ? $label : $fallback;
    }

    private function shouldExpose(Connection $connection, string $name, string $type, bool $filterable, bool $facet): bool
    {
        if ($this->isNeverFacet($name) || $type === 'json') {
            return false;
        }

        if (!$this->hasUsableFacetValues($connection, $name)) {
            return false;
        }

        return $facet || $filterable || in_array($name, self::FALLBACK_FACETABLE, true);
    }

    private function isNeverFacet(string $name): bool
    {
        $lowerName = strtolower($name);

        return in_array($name, self::NEVER_FACET, true)
            || str_ends_with($lowerName, 'url')
            || str_ends_with($lowerName, 'id')
            || str_contains($lowerName, 'wikidata')
            || str_contains($lowerName, 'latitude')
            || str_contains($lowerName, 'longitude');
    }

    private function hasUsableFacetValues(Connection $connection, string $field): bool
    {
        if (!$this->tableExists($connection, 'item_facet_count')) {
            return true;
        }

        $cacheKey = spl_object_id($connection) . ':' . $field;
        if (isset($this->usableFacetValuesCache[$cacheKey])) {
            return $this->usableFacetValuesCache[$cacheKey];
        }

        return $this->usableFacetValuesCache[$cacheKey] = (int) $connection->executeQuery(
            'SELECT COUNT(*) FROM item_facet_count WHERE field = :field',
            ['field' => $field],
        )->fetchOne() > 1;
    }

    private function tableExists(Connection $connection, string $table): bool
    {
        $cacheKey = spl_object_id($connection) . ':' . $table;
        if (isset($this->tableExistsCache[$cacheKey])) {
            return $this->tableExistsCache[$cacheKey];
        }

        return $this->tableExistsCache[$cacheKey] = $connection->executeQuery(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table",
            ['table' => $table],
        )->fetchOne() === $table;
    }

    private function humanize(string $field): string
    {
        $label = preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace(['_', ':'], ' ', $field)) ?? $field;

        return str_replace([' Uri', ' Url'], [' URI', ' URL'], ucwords($label));
    }
}
