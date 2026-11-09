<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Search;

use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Search\FolioDuckDbSearch;
use Survos\SearchBundle\Adapter\DuckDb\DuckDbAdapter;
use Survos\SearchBundle\Search\Filter\RangeFilter;
use Survos\SearchBundle\Search\Filter\TermFilter;
use Survos\SearchBundle\Twig\Components\Facet\RangeSlider;
use Survos\SearchBundle\Twig\Components\Facet\RefinementList;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DuckDbDeclaredFacetsTest extends TestCase
{
    public function testDeclaredListsAndYearRangesFilterTogether(): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('DuckDB required');
        }
        $db = new \PDO('duckdb::memory:');
        $db->exec('CREATE TABLE item(id VARCHAR, local_id VARCHAR, core_id VARCHAR, dto_type VARCHAR, dto_data JSON)');
        $db->exec('CREATE TABLE schema_property(name VARCHAR, label VARCHAR, type VARCHAR, visible INTEGER, facet INTEGER, filterable INTEGER, position INTEGER)');
        $db->exec("INSERT INTO schema_property VALUES ('year','Year','?int',1,1,1,0), ('collections','Collections','?array',1,1,1,1), ('missing','Missing','string',1,1,1,2), ('bad\''path','Unsafe','string',1,1,1,3), ('year','Year','?int',1,1,1,4)");
        $insert = $db->prepare('INSERT INTO item VALUES (?, ?, ?, ?, ?)');
        foreach ([['a', 2008, ['Film', 'Film']], ['b', 2012, ['Film', 'TV']], ['c', 2014, ['TV']]] as [$id,$year,$collections]) {
            $insert->execute([$id, $id, 'mus/test:obj', 'object', json_encode(compact('year', 'collections'))]);
        }
        $fields = FolioDuckDbSearch::declaredFacets($db);
        self::assertSame(['year', 'collections'], array_column($fields, 'name'));
        $search = (new FolioDuckDbSearch())->create(['folioCode' => 'mus/test']);
        $p = $search->getAdapterParameters();
        foreach ($fields as $field) {
            $search->addFacet($field['name'], $field['label'], $field['numeric'] ? RangeSlider::class : RefinementList::class);
            $p['facetColumns'][$field['name']] = $field['expression'];
            $p[$field['numeric'] ? 'rangeFacets' : 'listFacets'][] = $field['name'];
        }
        $adapter = new DuckDbAdapter($db);
        $resolver = new OptionsResolver();
        $adapter->configureParameters($resolver);
        $search->setResolvedAdapterParameters($resolver->resolve($p));
        $query = $search->createQuery()->addActiveFilter(new TermFilter('collections', ['Film']))->addActiveFilter(new RangeFilter('year', 2010, 2015));
        $result = $adapter->search($query, $search);
        self::assertSame(1, $result->getTotalResults());
        self::assertSame('b', $result->getHits()[0]->getData()['id']);
    }
}
