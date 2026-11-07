<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Metadata\ContentType;
use Symfony\Component\Filesystem\Filesystem;

/** Materialize the built folio's logical content, with DTO-declared analytical tables. */
final class DuckDbFolioExporter
{
    public const int SCHEMA_VERSION = 1;
    public const string DEFAULT_MEMORY_LIMIT = '6GB';
    /** Bytes of source JSON per INSERT when materializing a DTO table; see the comment where it is used. */
    private const int DTO_BATCH_BYTES = 2 * 1024 * 1024;

    /** Bytes of JSON ingested per statement when copying a source table; see where it is used. */
    private const int INGEST_CHUNK_BYTES = 32 * 1024 * 1024;

    public function __construct(
        private readonly FolioDtoTypeResolver $types = new FolioDtoTypeResolver(),
        private readonly DuckDbDtoSchema $schema = new DuckDbDtoSchema(),
    ) {
    }

    /** @return array{path:string,buildId:string,rows:int,counts:array<string,int>} */
    public function export(string $folio, string $target, ?callable $publishRend = null): array
    {
        if (!is_file($folio) || !DuckDbFolioFile::accepts($target) || !is_dir(dirname($target))) {
            throw new \InvalidArgumentException('Supply an existing folio and a local .dfolio or .duckdb target directory.');
        }
        $source = new \PDO('sqlite:file:'.$folio.'?mode=ro', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $source->beginTransaction(); // Pin one SQLite revision for the entire export.
        $identity = $source->query('SELECT * FROM _folio_build')->fetchAll(\PDO::FETCH_ASSOC);
        if (1 !== count($identity) || self::SCHEMA_VERSION !== (int) $identity[0]['schema_version']) {
            throw new \RuntimeException('Rebuild the folio with a supported shared build identity before exporting.');
        }
        $newspaper = (bool) $source->query('SELECT 1 FROM item WHERE dto_type = '.$source->quote(ContentType::NEWSPAPER).' LIMIT 1')->fetchColumn();
        if ($newspaper && null === $publishRend) {
            throw new \RuntimeException('Newspaper export requires a rendition publisher; geometry must not be embedded in .dfolio.');
        }
        if (!$newspaper) {
            $publishRend = null;
        }
        $fs = new Filesystem();
        $directory = dirname($target).'/.dfolio-export-'.bin2hex(random_bytes(8));
        $fs->mkdir($directory, 0700);
        $db = null;
        try {
            $db = new \PDO('duckdb:'.$directory.'/snapshot.dfolio', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            if (!str_starts_with((string) $db->query('SELECT version()')->fetchColumn(), 'v2.')) {
                throw new \RuntimeException('DuckDB 2.x is required.');
            }
            // DuckDB defaults to ~80% of the machine's RAM; an export shares the host with the apps, so cap it
            // and let it spill beside the target instead. DUCKDB_MEMORY_LIMIT overrides (e.g. 12GB, or 0 for DuckDB's default).
            $limit = (string) (getenv('DUCKDB_MEMORY_LIMIT') ?: self::DEFAULT_MEMORY_LIMIT);
            if ('0' !== $limit) {
                if (!preg_match('/^\d+(?:\.\d+)?\s?(?:KB|MB|GB|TB|KiB|MiB|GiB|TiB)$/i', $limit)) {
                    throw new \InvalidArgumentException('DUCKDB_MEMORY_LIMIT must look like 6GB, or be 0.');
                }
                $db->exec('SET memory_limit = '.$db->quote($limit));
                $db->exec('SET temp_directory = '.$db->quote($directory.'/spill'));
            }
            $db->beginTransaction();
            if (null !== $publishRend) {
                $refs = (new DuckDbRenditionExporter())->export($source, $identity[0]['build_id'], $directory, $publishRend);
                $db->exec('CREATE TABLE _renditions(issue_id VARCHAR PRIMARY KEY, uri VARCHAR, sha256 VARCHAR, bytes BIGINT, build_id VARCHAR)');
                $insert = $db->prepare('INSERT INTO _renditions VALUES (?,?,?,?,?)');
                foreach ($refs as $ref) {
                    $insert->execute(array_values($ref));
                }
                unset($insert);
            }
            $counts = isset($refs) ? ['main._renditions' => count($refs)] : [];
            $groupFile = $directory.'/grouping.jsonl';
            $groupHandle = null;
            $groupBytes = 0;
            $groupStaged = false;
            $flushGrouping = static function () use ($db, $groupFile, &$groupHandle, &$groupBytes, &$groupStaged): void {
                if (is_resource($groupHandle)) {
                    fclose($groupHandle);
                    $groupHandle = null;
                }
                if ($groupBytes > 0) {
                    DuckDbGroupingProjection::ingest($db, $groupFile, !$groupStaged);
                    $groupStaged = true;
                    $groupBytes = 0;
                }
            };
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
                $structure = $db->quote(json_encode($shape, JSON_THROW_ON_ERROR));
                // DuckDB's JSON reader holds several times the size of what it ingests, outside the buffer pool, so one
                // statement over a whole table makes the export's memory grow with the table. Ingest bounded chunks instead.
                $created = false;
                $ingest = static function () use ($db, $table, $structure, $file, &$created): void {
                    $select = 'SELECT unnest(json_transform_strict(json, '.$structure.')) FROM read_ndjson_objects('.$db->quote($file).')';
                    $db->exec(($created ? 'INSERT INTO '.self::identifier($table).' ' : 'CREATE TABLE '.self::identifier($table).' AS ').$select);
                    $created = true;
                };
                $handle = fopen($file, 'wb') ?: throw new \RuntimeException('Cannot write '.$file);
                $count = $chunkBytes = 0;
                try {
                    foreach ($source->query('SELECT * FROM '.self::identifier($table), \PDO::FETCH_ASSOC) as $row) {
                        if (null !== $publishRend && in_array($table, ['item', 'page'], true)) {
                            [$row] = NewspaperGeometry::splitRow($table, $row);
                        }
                        if (null !== $publishRend && 'item' === $table) {
                            foreach (DuckDbGroupingProjection::pages($row) as $page) {
                                $groupHandle ??= fopen($groupFile, 'wb') ?: throw new \RuntimeException('Cannot write '.$groupFile);
                                $groupLine = json_encode($page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
                                fwrite($groupHandle, $groupLine);
                                $groupBytes += strlen($groupLine);
                                if ($groupBytes >= self::INGEST_CHUNK_BYTES) {
                                    $flushGrouping();
                                }
                            }
                        }
                        $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
                        fwrite($handle, $line);
                        $chunkBytes += strlen($line);
                        ++$count;
                        if ($chunkBytes >= self::INGEST_CHUNK_BYTES) {
                            fclose($handle);
                            $ingest();
                            $handle = fopen($file, 'wb') ?: throw new \RuntimeException('Cannot write '.$file);
                            $chunkBytes = 0;
                        }
                    }
                    fclose($handle);
                    $handle = null;
                    if ($chunkBytes > 0) {
                        $ingest();
                    }
                } finally {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
                if (0 === $count) {
                    $columns = [];
                    foreach ($shape as $name => $type) {
                        $columns[] = self::identifier($name).' '.$type;
                    }
                    $db->exec('CREATE TABLE '.self::identifier($table).' ('.implode(', ', $columns).')');
                }
                $counts['main.'.$table] = $count;
                if ('link' === $table && !array_key_exists('ordinal', $shape)) {
                    $db->exec('ALTER TABLE link ADD COLUMN ordinal BIGINT');
                    if (array_key_exists('extras', $shape)) {
                        $db->exec("UPDATE link SET ordinal = CAST(json_extract_string(extras, '$.ordinal') AS BIGINT)");
                    }
                }
            }
            $flushGrouping();
            if ($groupStaged || isset($refs)) {
                $counts += DuckDbGroupingProjection::materialize($db);
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
                foreach ($shape as $name => $fieldType) {
                    $pointer = '/'.str_replace(['~', '/'], ['~0', '~1'], $name);
                    $value = 'json_extract(dto_data, '.$db->quote($pointer).')';
                    if (is_array($fieldType)) {
                        $value = 'CASE WHEN json_type('.$value.") = 'VARCHAR' THEN CAST(json_extract_string(dto_data, ".$db->quote($pointer).') AS JSON) ELSE '.$value.' END';
                    }
                    $fields[] = $db->quote($name).', '.$value;
                }
                // Sparse DTO serialization omits empty fields; explicitly supply SQL NULL.
                $payload = 'json_object('.implode(', ', $fields).')';
                $db->prepare('INSERT INTO _dto_schema VALUES (?, ?, ?)')->execute([$type, $class, $structure]);
                $table = 'dto.'.self::identifier($type);
                // json_object/json_transform_strict copy each source document several times per thread, so a type whose
                // rows are large (a newspaper issue carries its whole OCR text, up to ~1 MB) exhausts the memory limit in
                // one statement, and even in batches counted in rows. Fill the table in batches bounded by source bytes.
                $select = 'SELECT id AS "_itemId", core_id AS "_coreId", unnest(json_transform_strict('.$payload.', '.$db->quote($structure).')) FROM item WHERE dto_type = '.$db->quote($type);
                $db->exec('CREATE TABLE '.$table.' AS '.$select.' LIMIT 0');
                $batches = $db->query('SELECT min(rowid), max(rowid) FROM (SELECT rowid, floor(sum(length(dto_data)) OVER (ORDER BY rowid) / '.self::DTO_BATCH_BYTES.') AS bucket FROM item WHERE dto_type = '.$db->quote($type).') GROUP BY bucket ORDER BY 1')->fetchAll(\PDO::FETCH_NUM);
                foreach ($batches as [$first, $last]) {
                    $db->exec('INSERT INTO '.$table.' '.$select.' AND rowid BETWEEN '.(int) $first.' AND '.(int) $last);
                }
                $counts['dto.'.$type] = (int) $db->query('SELECT count(*) FROM '.$table)->fetchColumn();
            }
            $db->commit();
            $db->exec('CHECKPOINT');
            $db = null;
            $source->commit();
            if (is_file($directory.'/snapshot.dfolio.wal')) {
                throw new \RuntimeException('DuckDB export has an unmerged WAL.');
            }
            $fs->rename($directory.'/snapshot.dfolio', $target, overwrite: true);

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
