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

    public function build(array $options = []): void
    {
        $key = $options['folioCode'];
        DuckDbFolioConnection::validate($key);
        $this->setAvailableHitsPerPage([12, 24, 48]);
        $this->addFacet(ItemField::CONTENT_TYPE, 'Type');
        $facetColumns = [ItemField::CONTENT_TYPE => 'd.dto_type'];
        $listFacets = $rangeFacets = [];
        $ftsFunction = '';
        if (null !== $this->connection) {
            $db = $this->connection->connect(['folioCode' => $key]);
            if ((bool) $db->query("SELECT count(*) FROM duckdb_functions() WHERE schema_name = 'fts_main_item' AND function_name = 'match_bm25'")->fetchColumn()) {
                $ftsFunction = 'fts_main_item.match_bm25';
            }
            foreach (self::declaredFacets($db) as $field) {
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
        $title = "json_extract_string(d.dto_data, '$.".ItemField::TITLE."')";
        $description = "json_extract_string(d.dto_data, '$.".ItemField::DESCRIPTION."')";
        $thumbnail = "json_extract_string(d.dto_data, '$.".ItemField::THUMBNAIL_URL."')";
        $source = "COALESCE(NULLIF(json_extract_string(d.dto_data, '$.sourceUrl'), ''), json_extract_string(d.dto_data, '$.".ItemField::URL."'))";
        $this->setAdapterParameters([
            'table' => 'item', 'idColumn' => 'd.id',
            'selectColumns' => ['d.id', 'd.local_id AS "localId"', "split_part(d.core_id, ':', 2) AS \"coreCode\"", 'CAST(d.dto_data AS VARCHAR) AS "dtoData"', "'".$key."' AS \"datasetCode\"", $thumbnail.' AS "'.ItemField::THUMBNAIL_URL.'"', $source.' AS "'.ItemField::URL.'"', $title.' AS "'.ItemField::TITLE.'"', $description.' AS "'.ItemField::DESCRIPTION.'"', 'd.dto_type AS "'.ItemField::CONTENT_TYPE.'"'],
            'textColumns' => [$title, $description, "json_extract_string(d.dto_data, '$.".ItemField::SEARCH_SUMMARY."')"],
            'ftsFunction' => $ftsFunction, 'facetColumns' => $facetColumns, 'listFacets' => $listFacets, 'rangeFacets' => $rangeFacets,
            'sortColumns' => ['title:asc' => $title.' ASC NULLS LAST'],
            'connectionOptions' => ['folioCode' => $key, 'datasetCode' => $key],
            ...($this->vectors?->parameters($key) ?? []),
        ]);
    }

    /** Read portable facet declarations, without making every reader know individual datasets.
     * @return list<array{name:string,label:string,numeric:bool,list:bool,expression:string}>
     */
    public static function declaredFacets(\PDO $db): array
    {
        if (!(bool) $db->query("SELECT count(*) FROM information_schema.tables WHERE table_name = 'schema_property'")->fetchColumn()) {
            return []; // Older snapshots and minimal readers still support the Type facet.
        }
        $fields = $db->query('SELECT name, label, type FROM schema_property WHERE visible = 1 AND (facet = 1 OR filterable = 1) ORDER BY position, name')->fetchAll(\PDO::FETCH_ASSOC);
        $facets = $seen = [];
        foreach ($fields as $field) {
            $name = $field['name'];
            // Schema is imported data: never interpolate an arbitrary JSON path or SQL expression.
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $json = "json_extract(d.dto_data, '$.".$name."')";
            $value = "json_extract_string(d.dto_data, '$.".$name."')";
            $numeric = (bool) preg_match('/\b(int|integer|float|double|number|numeric)\b/i', $field['type']);
            $list = str_contains($field['type'], 'array') || str_contains($field['type'], '[]');
            $expression = $numeric ? 'try_cast('.$value.' AS DOUBLE)' : ($list
                ? "CASE WHEN json_type($json) = 'ARRAY' THEN from_json($json, '\"VARCHAR[]\"') ELSE CASE WHEN $value IS NULL THEN []::VARCHAR[] ELSE [$value] END END"
                : $value);
            $hasValue = $db->query('SELECT 1 FROM item d WHERE '.($list ? 'len('.$expression.') > 0' : $expression.' IS NOT NULL').' LIMIT 1')->fetchColumn();
            if (false === $hasValue) {
                continue;
            }
            $facets[] = ['name' => $name, 'label' => $field['label'] ?: ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $name)),
                'numeric' => $numeric, 'list' => $list, 'expression' => $expression];
        }

        return $facets;
    }

    public function getHitTemplate(): ?string
    {
        return '@SurvosFolioBundle/search/hits/folio_duckdb.html.twig';
    }
}
