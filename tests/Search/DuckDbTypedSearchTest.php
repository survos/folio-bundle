<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Search;

use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Path\DataPaths;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\FolioBundle\Search\FolioDuckDbSearch;
use Survos\FolioBundle\Service\DuckDbFolioConnection;
use Survos\FolioBundle\Service\DuckDbFolioReader;
use Survos\FolioBundle\Service\LocalDuckDbFolioLocator;
use Survos\SearchBundle\Adapter\DuckDb\DuckDbAdapter;
use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DuckDbTypedSearchTest extends TestCase
{
    public function testAnExportedFolioIsSearchedThroughItsTypedTables(): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Native PDO DuckDB required.');
        }
        $path = sys_get_temp_dir().'/folio-typed-'.bin2hex(random_bytes(6)).'.dfolio';
        try {
            $db = new \PDO('duckdb:'.$path);
            $db->exec('CREATE TABLE item (id VARCHAR, local_id VARCHAR, core_id VARCHAR, dto_type VARCHAR, dto_data VARIANT)');
            $db->exec('CREATE TABLE schema_property (name VARCHAR, label VARCHAR, type VARCHAR, visible INTEGER, facet INTEGER, filterable INTEGER, position INTEGER)');
            $db->exec("INSERT INTO schema_property VALUES ('year','Year','?int',1,1,1,0), ('cul','Culture','?array',1,1,1,1), ('rights','Rights','?string',1,1,1,2)");
            $db->exec('CREATE TABLE _dto_schema (dto_type VARCHAR, dto_class VARCHAR, structure JSON)');
            $db->exec('CREATE SCHEMA dto');
            // The same field is a typed list in one contract and untyped JSON in the other; print has no rights column.
            $db->exec('CREATE TABLE dto.painting ("_itemId" VARCHAR, "_coreId" VARCHAR, title VARCHAR, year BIGINT, cul VARCHAR[], rights VARCHAR, thumbnailUrl VARCHAR)');
            $db->exec('CREATE TABLE dto.print ("_itemId" VARCHAR, "_coreId" VARCHAR, title VARCHAR, year BIGINT, cul JSON)');
            $db->exec("INSERT INTO _dto_schema VALUES ('painting', 'P', '{\"title\":\"VARCHAR\",\"year\":\"BIGINT\",\"cul\":[\"VARCHAR\"],\"rights\":\"VARCHAR\",\"thumbnailUrl\":\"VARCHAR\"}'), ('print', 'Q', '{\"title\":\"VARCHAR\",\"year\":\"BIGINT\",\"cul\":\"JSON\"}')");
            $item = $db->prepare('INSERT INTO item VALUES (?, ?, ?, ?, CAST(CAST(? AS JSON) AS VARIANT))');
            $stored = [];
            foreach ([['a', 'painting', 'Landscape A', 1900, ['France', 'France'], 'CC0'], ['b', 'painting', 'Landscape B', 1950, ['France', 'Japan'], null],
                ['c', 'print', 'Landscape C', 1960, ['Japan'], null], ['d', 'print', 'Portrait', 1970, null, null]] as [$id, $type, $title, $year, $cul, $rights]) {
                $stored[$id] = '{"year": '.$year.',  "title": "'.$title.'"}';
                $item->execute(['mus/test:obj:'.$id, $id, 'mus/test:obj', $type, $stored[$id]]);
                if ('painting' === $type) {
                    $db->prepare('INSERT INTO dto.painting VALUES (?, ?, ?, ?, CAST(? AS VARCHAR[]), ?, ?)')->execute(['mus/test:obj:'.$id, 'mus/test:obj', $title, $year, json_encode($cul), $rights, 'https://example.org/'.$id.'.jpg']);
                } else {
                    $db->prepare('INSERT INTO dto.print VALUES (?, ?, ?, ?, ?)')->execute(['mus/test:obj:'.$id, 'mus/test:obj', $title, $year, null === $cul ? null : json_encode($cul)]);
                }
            }
            // The hit's thumbnail is the item's first page, not a field of the DTO.
            $db->exec('CREATE TABLE page (id VARCHAR, row_id VARCHAR, seq INTEGER, url VARCHAR, source_url VARCHAR)');
            $db->exec("INSERT INTO page VALUES ('mus/test:obj:a#2', 'mus/test:obj:a', 2, 's3://bucket/a-back.jpg', NULL), ('mus/test:obj:a#1', 'mus/test:obj:a', 1, 's3://bucket/a-front.jpg', NULL)");
            \Survos\FolioBundle\Service\DuckDbFolioExporter::embedPages($db);
            $item = null;
            $db->exec('CHECKPOINT');
            $db = null;

            $connection = new DuckDbFolioConnection(new LocalDuckDbFolioLocator(new DataPaths(dirname($path)), ['mus/test' => $path]), new DuckDbFolioReader(), true);
            $search = (new FolioDuckDbSearch(null, $connection))->create(['folioCode' => 'mus/test']);
            $parameters = $search->getAdapterParameters();
            self::assertStringContainsString('dto."painting"', $parameters['table']);
            foreach ([...$parameters['facetColumns'], ...$parameters['textColumns'], ...$parameters['sortColumns']] as $expression) {
                self::assertStringNotContainsString('dto_data', $expression);
            }
            self::assertSame([ItemField::CONTENT_TYPE, 'year', 'cul', 'rights'], array_map(static fn ($facet) => $facet->getProperty(), $search->getFacets()));

            $adapter = new DuckDbAdapter($connection);
            $resolver = new OptionsResolver();
            $adapter->configureParameters($resolver);
            $search->setResolvedAdapterParameters($resolver->resolve($parameters));

            $result = $adapter->search($search->createQuery()->setQueryString('landscape')->setActiveSort('title:asc'), $search);
            self::assertSame(3, $result->getTotalResults());
            $hit = $result->getHits()[0]->getData();
            self::assertSame('mus/test:obj:a', $hit['id']);
            self::assertSame('a', $hit['localId']);
            self::assertSame('obj', $hit['coreCode']);
            self::assertEquals(json_decode($stored['a'], true), json_decode($hit['dtoData'], true), 'The hit carries the stored document');
            self::assertSame('Landscape A', $hit[ItemField::TITLE]);
            self::assertSame('s3://bucket/a-front.jpg', $hit[ItemField::THUMBNAIL_URL]);
            self::assertNull($result->getHits()[1]->getData()[ItemField::THUMBNAIL_URL], 'An item without pages has no thumbnail');
            self::assertSame('painting', $hit[ItemField::CONTENT_TYPE]);
            self::assertSame(['France' => 2, 'Japan' => 2], $result->getFacetDistributions()['cul']->getValues());
            self::assertSame(['CC0' => 1], $result->getFacetDistributions()['rights']->getValues());
            self::assertSame(1900.0, (float) $result->getFacetStats()['year']->getMin());

            $query = $search->createQuery()->addActiveFilter(new TermFilter('cul', ['Japan']))->addActiveFilter(new RangeFilter('year', 1955, 1975));
            $result = $adapter->search($query, $search);
            self::assertSame(1, $result->getTotalResults());
            self::assertSame('c', $result->getHits()[0]->getData()['localId']);
            // Core scopes also apply to the typed UNION source and its facet distributions.
            $search->create(['folioCode' => 'mus/test', 'coreCode' => 'doc']);
            $search->setResolvedAdapterParameters($resolver->resolve($search->getAdapterParameters()));
            self::assertSame(0, $adapter->search($search->createQuery(), $search)->getTotalResults());
            $search->create(['folioCode' => 'mus/test', 'coreCode' => 'obj', 'dtoType' => 'print']);
            $search->setResolvedAdapterParameters($resolver->resolve($search->getAdapterParameters()));
            $result = $adapter->search($search->createQuery(), $search);
            self::assertSame(2, $result->getTotalResults());
            self::assertSame(['print' => 2], $result->getFacetDistributions()[ItemField::CONTENT_TYPE]->getValues());

            $view = new \Survos\FolioBundle\Service\DuckDbFolioView($connection, new \Survos\FolioBundle\Service\FolioDtoTypeResolver());
            $browse = $view->search('mus/test', 'obj');
            self::assertSame(4, $browse['cores']['obj']->rowCount);
            self::assertSame('obj', $browse['selectedCore']);
            $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
            $view->search('mus/test', 'missing');
        } finally {
            $db = null;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
