<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Search\FolioDuckDbSearch;
use Survos\FolioBundle\Search\FolioDuckDbVectorProviderInterface;
use Survos\SearchBundle\Adapter\DuckDb\DuckDbAdapter;
use Survos\SearchBundle\Search\Query;
use Survos\SearchBundle\Search\Url\CurrentRequest;
use Survos\SearchBundle\Search\Url\DefaultUrlFormater;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** The map uses the same reader, facet declarations and query semantics as DuckDB search. */
final readonly class DuckDbFolioMap
{
    private const string LAT = "try_cast(json_extract_string(d.dto_data, '$.latitude') AS DOUBLE)";
    private const string LNG = "try_cast(json_extract_string(d.dto_data, '$.longitude') AS DOUBLE)";
    private const string COORDINATES = self::LAT.' BETWEEN -90 AND 90 AND '.self::LNG.' BETWEEN -180 AND 180';

    public function __construct(
        private DuckDbFolioConnection $connection,
        private DefaultUrlFormater $urls,
        private ?FolioDuckDbVectorProviderInterface $vectors = null,
    ) {
    }

    public function hasCoordinates(string $folioCode): bool
    {
        return false !== $this->connection->connect(['folioCode' => $folioCode])
            ->query('SELECT 1 FROM item d WHERE '.self::COORDINATES.' LIMIT 1')->fetchColumn();
    }

    public function features(string $folioCode, array $source, string $bbox = ''): array
    {
        $search = new FolioDuckDbSearch($this->vectors, $this->connection);
        $search->create(['folioCode' => $folioCode]);
        $parameters = $search->getAdapterParameters();
        $parameters['where'] = self::COORDINATES;
        $parts = explode(',', $bbox);
        if (4 === count($parts) && 4 === count(array_filter($parts, 'is_numeric'))) {
            [$west, $south, $east, $north] = array_map('floatval', $parts);
            $parameters['where'] .= ' AND '.self::LAT.' BETWEEN ? AND ? AND '.self::LNG.' BETWEEN ? AND ?';
            $parameters['params'] = [$south, $north, $west, $east];
        }
        $adapter = new DuckDbAdapter($this->connection);
        $resolver = new OptionsResolver();
        $adapter->configureParameters($resolver);
        $search->setResolvedAdapterParameters($resolver->resolve($parameters));
        $query = new Query();
        $this->urls->applyFilters(new CurrentRequest('', $source), $search, $query);
        // Search pagination must not leave most of the matching places off the map.
        $query->setCurrentPage(1)->setActiveHitsPerPage(10000);
        $features = [];
        foreach ($adapter->search($query, $search)->getHits() as $hit) {
            $row = $hit->getData();
            $dto = json_decode($row['dtoData'], true, flags: JSON_THROW_ON_ERROR);
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [(float) $dto['longitude'], (float) $dto['latitude']]],
                'properties' => ['id' => $row['localId'], 'coreCode' => $row['coreCode'],
                    'label' => $dto['title'] ?? $row['localId'], 'city' => $dto['city'] ?? null,
                    'thumbnailUrl' => $dto['thumbnailUrl'] ?? null, 'year' => $dto['year'] ?? null],
            ];
        }

        return $features;
    }
}
