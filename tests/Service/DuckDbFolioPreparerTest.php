<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Service\DuckDbFolioComparator;
use Survos\FolioBundle\Service\DuckDbFolioPreparer;
use Survos\FolioBundle\Service\DuckDbFolioReader;
use Symfony\Component\Filesystem\Filesystem;

final class DuckDbFolioPreparerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Native PDO DuckDB 2.x is required.');
        }
        $this->directory = sys_get_temp_dir().'/duck-prepare-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory);
        $db = new \PDO('sqlite:'.$this->directory.'/fixture.folio');
        $db->exec("CREATE TABLE _folio_build (build_id TEXT, schema_version INTEGER); INSERT INTO _folio_build VALUES ('build-a', 1)");
        $db->exec('CREATE TABLE item (id TEXT PRIMARY KEY, core_id TEXT, dto_type TEXT, dto_data TEXT)');
        $db->prepare('INSERT INTO item VALUES (?, ?, ?, ?)')->execute(['fixture:obj:1', 'fixture:obj', 'painting', json_encode([
            'id' => '1', 'title' => 'Sword', 'description' => 'Ceremonial sword', 'year' => 0, 'hasImages' => false, 'cul' => ['Germany'],
        ], JSON_THROW_ON_ERROR)]);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            (new Filesystem())->remove($this->directory);
        }
    }

    public function testPreparationContentIdempotenceAndFtsUpgrade(): void
    {
        $folio = $this->directory.'/fixture.folio';
        $preparer = new DuckDbFolioPreparer();
        $first = $preparer->prepare($folio);
        self::assertFalse($first['reused']);
        self::assertSame(1, $first['rows']);
        self::assertTrue((new DuckDbFolioComparator())->compare($folio, $first['path'])['matched']);
        $hash = hash_file('sha256', $first['path']);
        self::assertTrue($preparer->prepare($folio)['reused']);
        self::assertSame($hash, hash_file('sha256', $first['path']));
        self::assertFalse($preparer->prepare($folio, fts: true)['reused']);
        $db = (new DuckDbFolioReader())->open($first['path']);
        $db->exec('LOAD fts');
        self::assertSame('fixture:obj:1', $db->query("SELECT id FROM search_records WHERE fts_main_search_records.match_bm25(id, 'sword') IS NOT NULL")->fetchColumn());
        self::assertSame(0, $db->query('SELECT year FROM search_records')->fetchColumn());
        self::assertSame('["Germany"]', $db->query('SELECT to_json(culture)::VARCHAR FROM search_records')->fetchColumn());
        $db = null;
        self::assertTrue($preparer->prepare($folio, fts: true)['reused']);
        $source = new \PDO('sqlite:'.$folio);
        $source->exec("UPDATE _folio_build SET build_id = 'build-b'");
        $source = null;
        self::assertFalse($preparer->prepare($folio, fts: true)['reused']);
    }

    public function testFailedRebuildKeepsPreviousCompanion(): void
    {
        $folio = $this->directory.'/fixture.folio';
        $preparer = new DuckDbFolioPreparer();
        $first = $preparer->prepare($folio, fts: true);
        $hash = hash_file('sha256', $first['path']);
        $source = new \PDO('sqlite:'.$folio);
        $source->exec("UPDATE _folio_build SET build_id = 'broken'");
        $source->exec("UPDATE item SET dto_data = json_set(dto_data, '$.year', 'invalid')");
        $source = null;
        try {
            $preparer->prepare($folio, fts: true);
            self::fail('Invalid DTO values must fail the build.');
        } catch (\PDOException) {
            self::assertSame($hash, hash_file('sha256', $first['path']));
            self::assertSame([], glob($this->directory.'/.prepare-*'));
        }
    }
}
