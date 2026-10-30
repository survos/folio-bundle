<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Search;

use Survos\DataContracts\Vocabulary\ItemField;
use Survos\FolioBundle\Service\DuckDbFolioConnection;
use Survos\SearchBundle\Attribute\AsSearch;
use Survos\SearchBundle\Search\AbstractSearch;
use Survos\SearchBundle\Search\HitTemplateSearchInterface;

#[AsSearch(index: 'folio_item', name: 'folio_duckdb', adapter: 'folio_duckdb')]
final class FolioDuckDbSearch extends AbstractSearch implements HitTemplateSearchInterface
{
    public function __construct(private readonly ?FolioDuckDbVectorProviderInterface $vectors = null)
    {
    }

    public function build(array $options = []): void
    {
        $key = $options['folioCode'];
        DuckDbFolioConnection::validate($key);
        $this->setAvailableHitsPerPage([12, 24, 48]);
        $this->addFacet(ItemField::CONTENT_TYPE, 'Type');
        $this->addAvailableSort(null, 'Relevance');
        $this->addAvailableSort('title:asc', 'Title A–Z');
        $title = "json_extract_string(d.dto_data, '$.".ItemField::TITLE."')";
        $description = "json_extract_string(d.dto_data, '$.".ItemField::DESCRIPTION."')";
        $thumbnail = "json_extract_string(d.dto_data, '$.".ItemField::THUMBNAIL_URL."')";
        $source = "COALESCE(NULLIF(json_extract_string(d.dto_data, '$.sourceUrl'), ''), json_extract_string(d.dto_data, '$.".ItemField::URL."'))";
        $this->setAdapterParameters([
            'table' => 'item', 'idColumn' => 'd.id',
            'selectColumns' => ['d.id', 'd.local_id AS "localId"', "split_part(d.core_id, ':', 2) AS \"coreCode\"", 'CAST(d.dto_data AS VARCHAR) AS "dtoData"', "'".$key."' AS \"datasetCode\"", $thumbnail.' AS "'.ItemField::THUMBNAIL_URL.'"', $source.' AS "'.ItemField::URL.'"', $title.' AS "'.ItemField::TITLE.'"', $description.' AS "'.ItemField::DESCRIPTION.'"', 'd.dto_type AS "'.ItemField::CONTENT_TYPE.'"'],
            'textColumns' => [$title, $description],
            'facetColumns' => [ItemField::CONTENT_TYPE => 'd.dto_type'],
            'sortColumns' => ['title:asc' => $title.' ASC NULLS LAST'],
            'connectionOptions' => ['folioCode' => $key, 'datasetCode' => $key],
            ...($this->vectors?->parameters($key) ?? []),
        ]);
    }

    public function getHitTemplate(): ?string
    {
        return '@SurvosFolioBundle/search/hits/folio_duckdb.html.twig';
    }
}
