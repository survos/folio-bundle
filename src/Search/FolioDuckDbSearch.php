<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Search;

use Survos\DataContracts\Vocabulary\ItemField;
use Survos\FolioBundle\Service\DuckDbFolioConnection;
use Survos\SearchBundle\Attribute\AsSearch;
use Survos\SearchBundle\Search\AbstractSearch;
use Survos\SearchBundle\Search\HitTemplateSearchInterface;
use Survos\SearchBundle\Twig\Components\Facet\RangeSlider;
use Survos\SearchBundle\Twig\Components\Facet\RefinementList;

#[AsSearch(index: 'folio_item', name: 'folio_duckdb', adapter: 'folio_duckdb')]
final class FolioDuckDbSearch extends AbstractSearch implements HitTemplateSearchInterface
{
    public function __construct(private readonly ?FolioDuckDbVectorProviderInterface $vectors = null, private readonly ?DuckDbFolioConnection $connection = null)
    {
    }

    /** Fields the search page shows or matches; each becomes a VARCHAR column of the typed source. */
    private const array TEXT_FIELDS = [ItemField::TITLE, ItemField::DESCRIPTION, ItemField::SEARCH_SUMMARY, ItemField::URL, 'sourceUrl'];

    /**
     * Options: folioCode; itemSource (bool) keeps the item table as the source, for a caller that scopes the
     * search with its own expressions over item columns.
     */
    public function build(array $options = []): void
    {
        $key = $options['folioCode'];
        DuckDbFolioConnection::validate($key);
        $this->setAvailableHitsPerPage([12, 24, 48]);
        $this->addFacet(ItemField::CONTENT_TYPE, 'Type');
        $facetColumns = [ItemField::CONTENT_TYPE => 'd.dto_type'];
        $listFacets = $rangeFacets = [];
        $ftsFunction = '';
        $table = 'item';
        $typed = false;
        $pages = false;
        $vectors = $this->vectors?->parameters($key) ?? [];
        if (null !== $this->connection) {
            $db = $this->connection->connect(['folioCode' => $key]);
            if ((bool) $db->query("SELECT count(*) FROM duckdb_functions() WHERE schema_name = 'fts_main_item' AND function_name = 'match_bm25'")->fetchColumn()) {
                $ftsFunction = 'fts_main_item.match_bm25';
            }
            // The embedding column is on item, so a folio with vectors keeps item as its source.
            $pages = (bool) $db->query("SELECT count(*) FROM information_schema.columns WHERE table_schema = 'main' AND table_name = 'item' AND column_name = 'pages'")->fetchColumn();
            $source = self::source($db, !isset($vectors['vectorColumn']) && !($options['itemSource'] ?? false));
            $table = $source['table'];
            $typed = $source['typed'];
            foreach ($source['facets'] as $field) {
                $name = $field['name'];
                if (isset($facetColumns[$name])) {
                    continue;
                }
                $this->addFacet($name, $field['label'], $field['numeric'] ? RangeSlider::class : RefinementList::class);
                $facetColumns[$name] = $field['expression'];
                if ($field['numeric']) {
                    $rangeFacets[] = $name;
                } elseif ($field['list']) {
                    $listFacets[] = $name;
                }
            }
        }
        $this->addAvailableSort(null, 'Relevance');
        $this->addAvailableSort('title:asc', 'Title A–Z');
        $field = static fn (string $name): string => $typed ? 'd."'.$name.'"' : 'CAST(d.dto_data."'.$name.'" AS VARCHAR)';
        $title = $field(ItemField::TITLE);
        $description = $field(ItemField::DESCRIPTION);
        $sourceUrl = 'COALESCE(NULLIF('.$field('sourceUrl').", ''), ".$field(ItemField::URL).')';
        // Parsing dto_data for every candidate reads the whole column. With a typed source the ranked query
        // reads typed columns only, and the adapter reads the stored document for the hits of the page.
        // A hit's thumbnail is the item's first page. The DTO's own image fields are copies of it at best.
        $thumbnail = ($pages ? 'pages[1].url' : 'NULL::VARCHAR').' AS "'.ItemField::THUMBNAIL_URL.'"';
        $payload = ['local_id AS "localId"', 'CAST(CAST(dto_data AS JSON) AS VARCHAR) AS "dtoData"', $thumbnail];
        $this->setAdapterParameters([
            'table' => $table, 'idColumn' => 'd.id',
            'selectColumns' => ['d.id', ...($typed ? [] : ['d.'.$payload[0], 'CAST(CAST(d.dto_data AS JSON) AS VARCHAR) AS "dtoData"', ($pages ? 'd.' : '').$thumbnail]), "split_part(d.core_id, ':', 2) AS \"coreCode\"", "'".$key."' AS \"datasetCode\"", $sourceUrl.' AS "'.ItemField::URL.'"', $title.' AS "'.ItemField::TITLE.'"', $description.' AS "'.ItemField::DESCRIPTION.'"', 'd.dto_type AS "'.ItemField::CONTENT_TYPE.'"'],
            'textColumns' => [$title, $description, $field(ItemField::SEARCH_SUMMARY)],
            'ftsFunction' => $ftsFunction, 'facetColumns' => $facetColumns, 'listFacets' => $listFacets, 'rangeFacets' => $rangeFacets,
            'sortColumns' => ['title:asc' => $title.' ASC NULLS LAST'],
            'connectionOptions' => ['folioCode' => $key, 'datasetCode' => $key],
            ...($typed ? ['hydrate' => ['table' => 'item', 'idColumn' => 'id', 'columns' => $payload]] : []),
            ...$vectors,
        ]);
    }

    /**
     * The table to search and its declared facets. A folio written by DuckDbFolioExporter has one typed table per
     * DTO type; their union is the source. Any other DuckDB folio is searched through the item.dto_data VARIANT.
     *
     * @return array{table:string,typed:bool,facets:list<array{name:string,label:string,numeric:bool,list:bool,expression:string}>}
     */
    public static function source(\PDO $db, bool $typed = true): array
    {
        $fields = self::declaredFields($db);
        $table = $typed ? self::typedTable($db, $fields) : null;
        $facets = [];
        if (null !== $table && [] !== $fields) {
            // One pass over the typed columns tells which declared fields hold a value.
            $probes = array_map(static fn (array $field): string => $field['list'] ? 'coalesce(max(len(d."f_'.$field['name'].'")), 0) > 0' : 'count(d."f_'.$field['name'].'") > 0', $fields);
            $present = $db->query('SELECT '.implode(', ', $probes).' FROM '.$table.' d')->fetch(\PDO::FETCH_NUM);
        }
        foreach ($fields as $index => $field) {
            $name = $field['name'];
            if (null !== $table) {
                $expression = 'd."f_'.$name.'"';
                $hasValue = (bool) $present[$index];
            } else {
                // dto_data is a VARIANT: a path reads one sub-column. A missing key is NULL.
                $path = 'd.dto_data."'.$name.'"';
                $expression = $field['numeric'] ? 'try_cast('.$path.' AS DOUBLE)' : ($field['list']
                    ? "CASE WHEN variant_typeof($path) LIKE 'ARRAY%' THEN CAST($path AS VARCHAR[]) WHEN CAST($path AS VARCHAR) IS NULL THEN []::VARCHAR[] ELSE [CAST($path AS VARCHAR)] END"
                    : 'CAST('.$path.' AS VARCHAR)');
                $hasValue = false !== $db->query('SELECT 1 FROM item d WHERE '.($field['list'] ? 'len('.$expression.') > 0' : $expression.' IS NOT NULL').' LIMIT 1')->fetchColumn();
            }
            if (!$hasValue) {
                continue;
            }
            $facets[] = $field + ['expression' => $expression];
        }

        return ['table' => $table ?? 'item', 'typed' => null !== $table, 'facets' => $facets];
    }

    /** Facets as expressions over item.dto_data, for a reader that queries the item table itself.
     * @return list<array{name:string,label:string,numeric:bool,list:bool,expression:string}>
     */
    public static function declaredFacets(\PDO $db): array
    {
        return self::source($db, false)['facets'];
    }

    /** Read portable facet declarations, without making every reader know individual datasets.
     * @return list<array{name:string,label:string,numeric:bool,list:bool}>
     */
    public static function declaredFields(\PDO $db): array
    {
        if (!(bool) $db->query("SELECT count(*) FROM information_schema.tables WHERE table_name = 'schema_property'")->fetchColumn()) {
            return []; // Older snapshots and minimal readers still support the Type facet.
        }
        $fields = $seen = [];
        foreach ($db->query('SELECT name, label, type FROM schema_property WHERE visible = 1 AND (facet = 1 OR filterable = 1) ORDER BY position, name')->fetchAll(\PDO::FETCH_ASSOC) as $field) {
            $name = $field['name'];
            // Schema is imported data: never interpolate an arbitrary JSON path or SQL expression.
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $fields[] = ['name' => $name, 'label' => $field['label'] ?: ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $name)),
                'numeric' => (bool) preg_match('/\b(int|integer|float|double|number|numeric)\b/i', $field['type']),
                'list' => str_contains($field['type'], 'array') || str_contains($field['type'], '[]')];
        }

        return $fields;
    }

    /**
     * One SELECT per DTO type, with the same columns in the same order: id, core_id, dto_type, the text fields,
     * then one f_<name> column per declared facet. A type that does not declare a field supplies NULL.
     *
     * @param list<array{name:string,label:string,numeric:bool,list:bool}> $fields
     */
    private static function typedTable(\PDO $db, array $fields): ?string
    {
        if (!(bool) $db->query("SELECT count(*) FROM information_schema.tables WHERE table_name = '_dto_schema'")->fetchColumn()) {
            return null;
        }
        $tables = $db->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'dto'")->fetchAll(\PDO::FETCH_COLUMN);
        $selects = [];
        foreach ($db->query('SELECT dto_type, structure::VARCHAR AS structure FROM _dto_schema ORDER BY dto_type')->fetchAll(\PDO::FETCH_ASSOC) as $contract) {
            if (!in_array($contract['dto_type'], $tables, true)) {
                return null;
            }
            $shape = json_decode($contract['structure'], true, flags: JSON_THROW_ON_ERROR);
            $columns = ['"_itemId" AS id', '"_coreId" AS core_id', $db->quote($contract['dto_type']).' AS dto_type'];
            foreach (self::TEXT_FIELDS as $name) {
                $columns[] = (isset($shape[$name]) ? 'CAST("'.$name.'" AS VARCHAR)' : 'NULL::VARCHAR').' AS "'.$name.'"';
            }
            foreach ($fields as $field) {
                $columns[] = self::typedFacet($shape[$field['name']] ?? null, $field).' AS "f_'.$field['name'].'"';
            }
            $selects[] = 'SELECT '.implode(', ', $columns).' FROM dto."'.str_replace('"', '""', $contract['dto_type']).'"';
        }

        return [] === $selects ? null : '('.implode(' UNION ALL ', $selects).')';
    }

    /**
     * @param string|list<string>|null                               $declared the column type in _dto_schema: a scalar type, 'JSON', or [element type]
     * @param array{name:string,label:string,numeric:bool,list:bool} $field
     */
    private static function typedFacet(string|array|null $declared, array $field): string
    {
        $column = '"'.$field['name'].'"';
        $json = 'JSON' === $declared;
        if ($field['numeric']) {
            return null === $declared || is_array($declared) ? 'NULL::DOUBLE' : 'try_cast('.($json ? "json_extract_string($column, '$')" : $column).' AS DOUBLE)';
        }
        if (!$field['list']) {
            return null === $declared ? 'NULL::VARCHAR' : ($json ? "json_extract_string($column, '$')" : 'CAST('.$column.' AS VARCHAR)');
        }

        return match (true) {
            null === $declared => '[]::VARCHAR[]',
            is_array($declared) => 'CAST('.$column.' AS VARCHAR[])',
            // An array property without a declared element type is exported as JSON.
            $json => "CASE WHEN json_type($column) = 'ARRAY' THEN from_json($column, '[\"VARCHAR\"]') WHEN json_extract_string($column, '$') IS NULL THEN []::VARCHAR[] ELSE [json_extract_string($column, '$')] END",
            default => "CASE WHEN $column IS NULL THEN []::VARCHAR[] ELSE [CAST($column AS VARCHAR)] END",
        };
    }

    public function getHitTemplate(): ?string
    {
        return '@SurvosFolioBundle/search/hits/folio_duckdb.html.twig';
    }
}
