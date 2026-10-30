<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Service\DuckDbCompanion;
use Survos\FolioBundle\Service\DuckDbFolioComparator;
use Survos\FolioBundle\Service\DuckDbFolioExporter;
use Symfony\Component\Filesystem\Filesystem;

final class DuckDbFolioExporterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Native DuckDB is required.');
        }
        $this->dir = sys_get_temp_dir().'/folio-export-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->dir);
        $db = new \PDO('sqlite:'.$this->dir.'/fixture.folio');
        $db->exec("CREATE TABLE _folio_build (build_id TEXT, schema_version INTEGER); INSERT INTO _folio_build VALUES ('build-a', 1)");
        $db->exec('CREATE TABLE item (id TEXT PRIMARY KEY, core_id TEXT, local_id TEXT, dto_type TEXT, dto_data TEXT, extras TEXT, sort_key INTEGER GENERATED ALWAYS AS (json_extract(dto_data, \'$.year\')) VIRTUAL)');
        $db->prepare('INSERT INTO item(id,core_id,local_id,dto_type,dto_data,extras) VALUES (?, ?, ?, ?, ?, ?)')->execute([
            'fixture:obj:001', 'fixture:obj', '001', 'painting', json_encode(['id' => '001', 'title' => '雪', 'year' => 1900, 'hasImages' => false, 'aiTasks' => ['observe'], 'dimensions' => [['height' => 12.5]], 'description' => null], JSON_THROW_ON_ERROR), '{"custom":"retained"}',
        ]);
        $db->exec('CREATE TABLE page (id TEXT PRIMARY KEY, width INTEGER, text TEXT)');
        $db->exec("INSERT INTO page VALUES ('p1', NULL, '')");
        $db->exec('CREATE TABLE empty_table (value TEXT)');
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            (new Filesystem())->remove($this->dir);
        }
    }

    public function testDeclaredSchemaContentAndMatchingCompanion(): void
    {
        $result = (new DuckDbFolioExporter())->export($this->dir.'/fixture.folio', $this->dir.'/fixture.duckdb');
        self::assertSame('build-a', $result['buildId']);
        $db = (new DuckDbCompanion($result['path'], 'build-a', 1))->open();
        self::assertSame('BIGINT', $db->query('SELECT typeof(year) FROM dto.painting')->fetchColumn());
        self::assertSame('VARCHAR[]', $db->query('SELECT typeof("aiTasks") FROM dto.painting')->fetchColumn());
        self::assertNull($db->query('SELECT "meritOverall" FROM dto.painting')->fetchColumn());
        self::assertSame(1900, $db->query('SELECT sort_key FROM item')->fetchColumn());
        $comparison = (new DuckDbFolioComparator())->compare($this->dir.'/fixture.folio', $result['path']);
        self::assertTrue($comparison['matched']);
        self::assertSame(['painting' => 1], $comparison['dtoProjections']);
        $this->expectException(\PDOException::class);
        $db->exec('DELETE FROM item');
    }

    public function testStaleCompanionRejectedAndMissingCompanionOptional(): void
    {
        self::assertNull((new DuckDbCompanion($this->dir.'/absent.duckdb', 'build-a', 1))->open());
        (new DuckDbFolioExporter())->export($this->dir.'/fixture.folio', $this->dir.'/fixture.duckdb');
        $this->expectException(\RuntimeException::class);
        (new DuckDbCompanion($this->dir.'/fixture.duckdb', 'build-b', 1))->open();
    }

    public function testInvalidTypedRebuildPreservesPublishedDuck(): void
    {
        $exporter = new DuckDbFolioExporter();
        $exporter->export($this->dir.'/fixture.folio', $this->dir.'/fixture.duckdb');
        $hash = hash_file('sha256', $this->dir.'/fixture.duckdb');
        $source = new \PDO('sqlite:'.$this->dir.'/fixture.folio');
        $source->prepare('UPDATE item SET dto_data = ?')->execute(['{"id":"001","year":"not-a-year"}']);
        $source = null;
        try {
            $exporter->export($this->dir.'/fixture.folio', $this->dir.'/fixture.duckdb');
            self::fail('Invalid integer must not be silently replaced by NULL.');
        } catch (\PDOException) {
            self::assertSame($hash, hash_file('sha256', $this->dir.'/fixture.duckdb'));
        }
    }

    public function testWrongValueFailsComparisonEvenWithEqualCounts(): void
    {
        (new DuckDbFolioExporter())->export($this->dir.'/fixture.folio', $this->dir.'/fixture.duckdb');
        $db = new \PDO('duckdb:'.$this->dir.'/fixture.duckdb');
        $db->exec("UPDATE dto.painting SET title = 'wrong'");
        $db = null;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DTO value mismatch');
        (new DuckDbFolioComparator())->compare($this->dir.'/fixture.folio', $this->dir.'/fixture.duckdb');
    }
}
