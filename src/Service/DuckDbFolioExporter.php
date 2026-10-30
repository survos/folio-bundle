<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Filesystem\Filesystem;

/** Materialize the built folio's logical content, with DTO-declared analytical tables. */
final class DuckDbFolioExporter
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        private readonly FolioDtoTypeResolver $types = new FolioDtoTypeResolver(),
        private readonly DuckDbDtoSchema $schema = new DuckDbDtoSchema(),
    ) {
    }

    /** @return array{path:string,buildId:string,rows:int,counts:array<string,int>} */
    public function export(string $folio, string $target): array
    {
        if (!is_file($folio) || !str_ends_with($target, '.duckdb') || !is_dir(dirname($target))) {
            throw new \InvalidArgumentException('Supply an existing folio and a local .duckdb target directory.');
        }
        $source = new \PDO('sqlite:file:'.$folio.'?mode=ro', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $source->beginTransaction(); // Pin one SQLite revision for the entire export.
        $identity = $source->query('SELECT * FROM _folio_build')->fetchAll(\PDO::FETCH_ASSOC);
        if (1 !== count($identity) || self::SCHEMA_VERSION !== (int) $identity[0]['schema_version']) {
            throw new \RuntimeException('Rebuild the folio with a supported shared build identity before exporting.');
        }
        $fs = new Filesystem();
        $directory = dirname($target).'/.duckdb-export-'.bin2hex(random_bytes(8));
        $fs->mkdir($directory, 0700);
        $db = null;
        try {
            $db = new \PDO('duckdb:'.$directory.'/snapshot.duckdb', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            if (!str_starts_with((string) $db->query('SELECT version()')->fetchColumn(), 'v2.')) {
                throw new \RuntimeException('DuckDB 2.x is required.');
            }
            $db->beginTransaction();
            $counts = [];
            foreach (self::tables($source) as $table) {
                $shape = [];
                foreach ($source->query('PRAGMA table_xinfo('.self::identifier($table).')') as $column) {
                    $type = strtoupper($column['type']);
                    $shape[$column['name']] = match (true) {
                        str_contains($type, 'INT'), str_contains($type, 'BOOL') => 'BIGINT',
                        str_contains($type, 'REAL'), str_contains($type, 'FLOAT'), str_contains($type, 'DOUBLE') => 'DOUBLE',
                        str_contains($type, 'BLOB') => throw new \RuntimeException('Binary columns need an explicit export contract: '.$table.'.'.$column['name']),
                        default => 'VARCHAR',
                    };
                }
                $file = $directory.'/table.jsonl';
                $writer = JsonlWriter::open($file);
                $count = 0;
                try {
                    foreach ($source->query('SELECT * FROM '.self::identifier($table), \PDO::FETCH_ASSOC) as $row) {
                        $writer->write($row);
                        ++$count;
                    }
                    $writer->finish();
                } finally {
                    $writer->close();
                }
                $structure = $db->quote(json_encode($shape, JSON_THROW_ON_ERROR));
                if (0 === $count) {
                    $columns = [];
                    foreach ($shape as $name => $type) {
                        $columns[] = self::identifier($name).' '.$type;
                    }
                    $db->exec('CREATE TABLE '.self::identifier($table).' ('.implode(', ', $columns).')');
                } else {
                    $db->exec('CREATE TABLE '.self::identifier($table).' AS SELECT unnest(json_transform_strict(json, '.$structure.')) FROM read_ndjson_objects('.$db->quote($file).')');
                }
                $counts['main.'.$table] = $count;
            }
            if (0 === ($counts['main.item'] ?? 0)) {
                throw new \RuntimeException('Refusing to export an empty folio.');
            }
            $db->exec('CREATE SCHEMA dto');
            $db->exec('CREATE TABLE _dto_schema (dto_type VARCHAR, dto_class VARCHAR, structure JSON)');
            foreach ($source->query('SELECT DISTINCT dto_type FROM item ORDER BY dto_type')->fetchAll(\PDO::FETCH_COLUMN) as $type) {
                $class = $this->types->classForType($type) ?? throw new \RuntimeException('No DTO contract for '.$type);
                $shape = $this->schema->structure($class);
                if (array_key_exists('_itemId', $shape) || array_key_exists('_coreId', $shape)) {
                    throw new \LogicException('DTO uses a reserved identity column.');
                }
                $structure = json_encode($shape, JSON_THROW_ON_ERROR);
                $fields = [];
                foreach ($shape as $name => $_) {
                    $pointer = '/'.str_replace(['~', '/'], ['~0', '~1'], $name);
                    $fields[] = $db->quote($name).', json_extract(dto_data, '.$db->quote($pointer).')';
                }
                // Sparse DTO serialization omits empty fields; explicitly supply SQL NULL.
                $payload = 'json_object('.implode(', ', $fields).')';
                $db->prepare('INSERT INTO _dto_schema VALUES (?, ?, ?)')->execute([$type, $class, $structure]);
                $table = 'dto.'.self::identifier($type);
                $db->exec('CREATE TABLE '.$table.' AS SELECT id AS "_itemId", core_id AS "_coreId", unnest(json_transform_strict('.$payload.', '.$db->quote($structure).')) FROM item WHERE dto_type = '.$db->quote($type));
                $counts['dto.'.$type] = (int) $db->query('SELECT count(*) FROM '.$table)->fetchColumn();
            }
            $db->commit();
            $db->exec('CHECKPOINT');
            $db = null;
            $source->commit();
            if (is_file($directory.'/snapshot.duckdb.wal')) {
                throw new \RuntimeException('DuckDB export has an unmerged WAL.');
            }
            $fs->rename($directory.'/snapshot.duckdb', $target, overwrite: true);

            return ['path' => $target, 'buildId' => $identity[0]['build_id'], 'rows' => $counts['main.item'], 'counts' => $counts];
        } finally {
            $db = null;
            $source = null;
            $fs->remove($directory);
        }
    }

    /** User tables, excluding SQLite internals and FTS virtual tables/shadow storage. */
    public static function tables(\PDO $source): array
    {
        $tables = [];
        foreach ($source->query('PRAGMA table_list', \PDO::FETCH_ASSOC) as $row) {
            if ('main' === $row['schema'] && 'table' === $row['type'] && !str_starts_with($row['name'], 'sqlite_')) {
                $tables[] = $row['name'];
            }
        }
        sort($tables);

        return $tables;
    }

    public static function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
