<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\FolioBundle\Dto\FolioBuildInput;
use Survos\FolioBundle\Service\DuckDbFolioBuilder;
use Symfony\Component\Filesystem\Filesystem;

final class DuckDbFolioBuilderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Requires ext-pdo_duckdb with DuckDB 2.0.');
        }
        $this->root = (getenv('FOLIO_TEST_ROOT') ?: sys_get_temp_dir()).'/duckdb-folio-'.bin2hex(random_bytes(5));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            (new Filesystem())->remove($this->root);
        }
    }

    public function testBulkSnapshotPreservesSparseNestedAndOriginalJson(): void
    {
        $objects = [
            [ItemField::ID => '0001', ItemField::TITLE => 'First', ItemField::KEYWORDS => ['one']],
            [ItemField::ID => '0002', ItemField::TITLE => 'Second', ItemField::DESCRIPTION => null, ItemField::KEYWORDS => ['two']],
            [ItemField::ID => '0003', ItemField::TITLE => 'Third', ItemField::DESCRIPTION => 'Late field'],
        ];
        file_put_contents($this->root.'/obj.jsonl.gz', gzencode(implode("\n", array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), $objects))."\n"));
        file_put_contents($this->root.'/page.jsonl', '');
        $result = (new DuckDbFolioBuilder())->build(new FolioBuildInput(
            'test/snapshot', ['obj' => $this->root.'/obj.jsonl.gz'], [], 'en', files: ['page' => $this->root.'/page.jsonl'],
        ), $this->root.'/snapshot.duckdb');
        self::assertSame(3, $result['rows']);
        self::assertSame(['main.obj' => 3, 'aux.page' => 0], $result['counts']);
        $db = $this->open();
        $statement = $db->prepare('SELECT "'.ItemField::TITLE.'" FROM obj WHERE "'.ItemField::ID.'" = ?');
        $statement->execute(['0002']);
        self::assertSame('Second', $statement->fetchColumn());
        $statement = null;
        self::assertSame('Late field', $db->query('SELECT "'.ItemField::DESCRIPTION.'" FROM obj ORDER BY "'.ItemField::ID.'" DESC LIMIT 1')->fetchColumn());
        $raw = $db->query('SELECT json::VARCHAR FROM raw_core.obj')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertEqualsCanonicalizing($objects, array_map(static fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $raw));
        self::assertSame([], glob($this->root.'/.duckdb-build-*'));
    }

    public function testMalformedRebuildPreservesExistingSnapshot(): void
    {
        $file = $this->root.'/obj.jsonl';
        file_put_contents($file, json_encode([ItemField::ID => '1'], JSON_THROW_ON_ERROR)."\n");
        $builder = new DuckDbFolioBuilder();
        $input = new FolioBuildInput('test/snapshot', ['obj' => $file], [], 'en');
        $target = $this->root.'/snapshot.duckdb';
        $builder->build($input, $target);
        $before = hash_file('sha256', $target);
        file_put_contents($file, "{broken\n");
        try {
            $builder->build($input, $target);
            self::fail('Malformed JSONL must fail.');
        } catch (\PDOException) {
            self::assertSame($before, hash_file('sha256', $target));
            self::assertSame([], glob($this->root.'/.duckdb-build-*'));
        }
    }

    public function testEmptySourceDoesNotPublish(): void
    {
        file_put_contents($this->root.'/obj.jsonl', '');
        try {
            (new DuckDbFolioBuilder())->build(new FolioBuildInput('test/empty', ['obj' => $this->root.'/obj.jsonl'], [], 'en'), $this->root.'/snapshot.duckdb');
            self::fail('Empty row cores must fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('empty DuckDB folio', $e->getMessage());
            self::assertFileDoesNotExist($this->root.'/snapshot.duckdb');
        }
    }

    private function open(): \PDO
    {
        return new \PDO('duckdb:'.$this->root.'/snapshot.duckdb', options: [\PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only']]);
    }
}
