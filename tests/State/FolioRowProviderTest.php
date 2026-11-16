<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\State;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Path\DataPaths;
use Survos\FolioBundle\Entity\Row;
use Survos\FolioBundle\Service\DuckDbFolioConnection;
use Survos\FolioBundle\Service\DuckDbFolioExporter;
use Survos\FolioBundle\Service\DuckDbFolioReader;
use Survos\FolioBundle\Service\LocalDuckDbFolioLocator;
use Survos\FolioBundle\State\FolioRowProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class FolioRowProviderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Native PDO DuckDB required.');
        }
        $this->path = sys_get_temp_dir().'/folio-rows-'.bin2hex(random_bytes(6)).'.dfolio';
        $db = new \PDO('duckdb:'.$this->path);
        $db->exec('CREATE TABLE item (id VARCHAR PRIMARY KEY, core_id VARCHAR, local_id VARCHAR, label VARCHAR, dto_type VARCHAR, dto_data VARIANT, extras VARIANT, raw VARIANT, sort_key BIGINT)');
        $insert = $db->prepare('INSERT INTO item VALUES (?, ?, ?, ?, ?, CAST(CAST(? AS JSON) AS VARIANT), NULL, NULL, ?)');
        foreach ([['b', 'Later', 'interview', 1950], ['a', 'Earlier', 'interview', 1941], ['c', 'Undated', 'interview', null], ['d', 'A letter', 'manuscript', 1942]] as [$id, $label, $type, $year]) {
            $insert->execute(['test/voices:obj:'.$id, 'test/voices:obj', $id, $label, $type, json_encode(['title' => $label, 'recordKind' => 'd' === $id ? 'advertisement' : 'article']), $year]);
        }
        $insert->execute(['test/voices:per:p', 'test/voices:per', 'p', 'A person', 'person', '{}', null]);
        $db->exec('CREATE TABLE page (id VARCHAR, row_id VARCHAR, seq INTEGER, page_index INTEGER, type VARCHAR, url VARCHAR, source_url VARCHAR, media_id VARCHAR, width INTEGER, height INTEGER, text VARCHAR, htr VARCHAR, dense_summary VARCHAR, thumb_hash VARCHAR, color VARCHAR, source_checksum VARCHAR, layout VARCHAR, ledger VARCHAR, dialogue VARCHAR)');
        $db->exec("INSERT INTO page (id, row_id, seq, page_index, type, url, text) VALUES ('test/voices:obj:a#1', 'test/voices:obj:a', 1, 0, 'audio', 'https://media.example/a.mp3', 'We heard it on the radio.')");
        DuckDbFolioExporter::embedPages($db);
        // The API reads an item and its pages from the item row.
        $db->exec('DROP TABLE page');
        $db->exec('CHECKPOINT');
    }

    protected function tearDown(): void
    {
        if (isset($this->path) && is_file($this->path)) {
            unlink($this->path);
        }
    }

    private function provider(array $query): FolioRowProvider
    {
        $request = Request::create('/api/test/voices/rows/grid', 'GET', $query);
        $request->attributes->set('folioCode', 'test/voices');
        $stack = new RequestStack();
        $stack->push($request);
        $connection = new DuckDbFolioConnection(new LocalDuckDbFolioLocator(new DataPaths(dirname($this->path)), ['test/voices' => $this->path]), new DuckDbFolioReader(), true);

        return new FolioRowProvider($connection, $stack);
    }

    public function testACollectionIsReadFromTheDfolioInYearOrder(): void
    {
        $page = $this->provider(['itemsPerPage' => 2])->provide(new GetCollection(), [], ['filters' => []]);
        self::assertSame(4.0, (float) $page->getTotalItems());
        $rows = iterator_to_array($page);
        self::assertContainsOnlyInstancesOf(Row::class, $rows);
        self::assertSame(['a', 'd'], array_map(static fn (Row $r): string => $r->localId, $rows));
        self::assertSame('https://media.example/a.mp3', $rows[0]->getRawThumbnailSource());
        self::assertSame('Earlier', $rows[0]->dtoData['title']);

        $second = iterator_to_array($this->provider(['itemsPerPage' => 2, 'page' => 2])->provide(new GetCollection(), [], ['filters' => []]));
        self::assertSame(['b', 'c'], array_map(static fn (Row $r): string => $r->localId, $second), 'Undated rows come last');
    }

    public function testFiltersAreAppliedInTheDfolio(): void
    {
        $ids = static fn (iterable $rows): array => array_map(static fn (Row $r): string => $r->localId, iterator_to_array($rows));
        self::assertSame(['d'], $ids($this->provider([])->provide(new GetCollection(), [], ['filters' => ['dtoType' => 'manuscript']])));
        self::assertSame(['d', 'b'], $ids($this->provider([])->provide(new GetCollection(), [], ['filters' => ['yearMin' => '1942']])), 'yearMin leaves undated rows out');
        self::assertSame(['d'], $ids($this->provider([])->provide(new GetCollection(), [], ['filters' => ['recordKind' => 'advertisement']])));
        self::assertSame(['a'], $ids($this->provider([])->provide(new GetCollection(), [], ['filters' => ['q' => 'earl']])));
        self::assertSame(['p'], $ids($this->provider(['core' => 'per'])->provide(new GetCollection(), [], ['filters' => []])));
    }

    public function testOneRowCarriesItsPages(): void
    {
        $row = $this->provider([])->provide(new Get(), ['localId' => 'a'], []);
        self::assertInstanceOf(Row::class, $row);
        self::assertCount(1, $row->pages);
        self::assertSame('We heard it on the radio.', $row->pages[0]->text);
        self::assertNull($this->provider([])->provide(new Get(), ['localId' => 'missing'], []));
    }
}
