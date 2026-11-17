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
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DuckDbEntityNameSearchTest extends TestCase
{
    public function testNamesDisplaySearchAndSortForBothStorageShapes(): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Native DuckDB required.');
        }
        $path = sys_get_temp_dir().'/folio-names-'.bin2hex(random_bytes(6)).'.dfolio';
        try {
            $db = new \PDO('duckdb:'.$path);
            $db->exec('CREATE TABLE item (id VARCHAR, local_id VARCHAR, core_id VARCHAR, dto_type VARCHAR, dto_data VARIANT)');
            $db->exec('CREATE TABLE _dto_schema (dto_type VARCHAR, structure JSON)');
            $db->exec('CREATE SCHEMA dto');
            $db->exec('CREATE TABLE dto.person ("_itemId" VARCHAR, "_coreId" VARCHAR, name VARCHAR)');
            $db->exec('INSERT INTO _dto_schema VALUES (\'person\', \'{"name":"VARCHAR"}\')');
            $insert = $db->prepare('INSERT INTO item VALUES (?, ?, ?, ?, CAST(CAST(? AS JSON) AS VARIANT))');
            $typedInsert = $db->prepare('INSERT INTO dto.person VALUES (?, ?, ?)');
            foreach (['dwight' => 'Dwight, Joseph', 'clark' => 'Clark, Ebenezer'] as $id => $name) {
                $insert->execute(['dc/test:per:'.$id, $id, 'dc/test:per', 'person', json_encode([ItemField::NAME => $name], JSON_THROW_ON_ERROR)]);
                $typedInsert->execute(['dc/test:per:'.$id, 'dc/test:per', $name]);
            }
            $insert = $typedInsert = null;
            $db->exec('CHECKPOINT');
            $db = null;
            $connection = new DuckDbFolioConnection(new LocalDuckDbFolioLocator(new DataPaths(dirname($path)), ['dc/test' => $path]), new DuckDbFolioReader(), true);
            foreach ([true, false] as $itemSource) {
                $search = (new FolioDuckDbSearch(null, $connection))->create(['folioCode' => 'dc/test', 'coreCode' => 'per', 'itemSource' => $itemSource]);
                $adapter = new DuckDbAdapter($connection);
                $resolver = new OptionsResolver();
                $adapter->configureParameters($resolver);
                $search->setResolvedAdapterParameters($resolver->resolve($search->getAdapterParameters()));
                $query = $search->createQuery()->setActiveSort('title:asc');
                $result = $adapter->search($query, $search);
                self::assertSame(['Clark, Ebenezer', 'Dwight, Joseph'], array_map(static fn ($hit) => $hit->getData()[ItemField::TITLE], $result->getHits()));
                $query->setQueryString('Ebenezer');
                $result = $adapter->search($query, $search);
                self::assertSame(1, $result->getTotalResults());
                self::assertSame('dc/test:per:clark', $result->getHits()[0]->getData()[ItemField::ID]);
                self::assertSame([ItemField::NAME => 'Clark, Ebenezer'], json_decode($result->getHits()[0]->getData()['dtoData'], true), 'Presentation does not rewrite the stored entity.');
            }
        } finally {
            $db = null;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
