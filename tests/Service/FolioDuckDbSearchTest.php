<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\FolioBundle\Search\FolioDuckDbSearch;
use Survos\SearchBundle\Adapter\DuckDb\DuckDbAdapter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class FolioDuckDbSearchTest extends TestCase
{
    public function testSearchFacetsSortAndPaginationUseTheSharedAdapter(): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Native DuckDB required.');
        }
        $db = new \PDO('duckdb::memory:');
        $db->exec('CREATE TABLE item (id VARCHAR, dto_type VARCHAR, dto_data JSON, local_id VARCHAR, core_id VARCHAR)');
        $insert = $db->prepare('INSERT INTO item (id, dto_type, dto_data) VALUES (?, ?, ?)');
        foreach ([['a', 'painting', 'Landscape A'], ['b', 'painting', 'Landscape B'], ['c', 'print', 'Landscape C'], ['d', 'print', 'Portrait']] as [$id, $type, $title]) {
            $insert->execute([$id, $type, json_encode([ItemField::TITLE => $title, 'sourceUrl' => 'https://example.org/source/'.$id, ItemField::URL => 'urn:'.$id])]);
        }
        $search = (new FolioDuckDbSearch())->create(['folioCode' => 'mus/cleveland']);
        $adapter = new DuckDbAdapter($db);
        $resolver = new OptionsResolver();
        $adapter->configureParameters($resolver);
        $search->setResolvedAdapterParameters($resolver->resolve($search->getAdapterParameters()));
        $query = $search->createQuery()->setQueryString('landscape')->setActiveHitsPerPage(1)->setActiveSort('title:asc');
        $result = $adapter->search($query, $search);
        self::assertSame(3, $result->getTotalResults());
        self::assertIsString($result->getHits()[0]->getData()['dtoData']);
        self::assertSame('https://example.org/source/a', $result->getHits()[0]->getData()[ItemField::URL]);
        self::assertSame('a', $result->getHits()[0]->getData()[ItemField::ID]);
        $query->setCurrentPage(2);
        self::assertSame('b', $adapter->search($query, $search)->getHits()[0]->getData()[ItemField::ID]);
        $query->setCurrentPage(1)->addActiveFilter(new TermFilter(ItemField::CONTENT_TYPE, ['print']));
        self::assertSame(1, $adapter->search($query, $search)->getTotalResults());
        $query->setQueryString("' OR 1=1 --");
        self::assertSame(0, $adapter->search($query, $search)->getTotalResults());
        $search->create(['folioCode' => 'mus/walters']);
        self::assertCount(1, $search->getFacets());
        self::assertSame('mus/walters', $search->getAdapterParameters()['connectionOptions']['datasetCode']);
    }

    public function testOptionalVectorProviderEnablesOnlyItsDataset(): void
    {
        $provider = new class implements \Survos\FolioBundle\Search\FolioDuckDbVectorProviderInterface {
            public function parameters(string $folioCode): array
            {
                return 'mus/cleveland' === $folioCode ? [
                    'vectorColumn' => 'd.embedding', 'vectorDimensions' => 3,
                    'minimumSimilarity' => 0.3,
                    'vectorCoverage' => ['indexed' => 200, 'total' => 1000],
                ] : [];
            }
        };
        $search = (new FolioDuckDbSearch($provider))->create(['folioCode' => 'mus/cleveland']);
        self::assertSame(200, $search->getAdapterParameters()['vectorCoverage']['indexed']);
        $search->create(['folioCode' => 'mus/walters']);
        self::assertArrayNotHasKey('vectorColumn', $search->getAdapterParameters());
    }

    public function testArbitraryDatasetPathsAreRejected(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        (new FolioDuckDbSearch())->create(['folioCode' => '/tmp/private.duckdb']);
    }
}
