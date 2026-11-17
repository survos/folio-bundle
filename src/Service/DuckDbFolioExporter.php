<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Metadata\ContentType;
use Survos\DataContracts\Vocabulary\ItemField;
use Symfony\Component\Filesystem\Filesystem;

/** Materialize the built folio's logical content, with DTO-declared analytical tables. */
final class DuckDbFolioExporter
{
    public const int SCHEMA_VERSION = 1;
    /** A dfolio built smaller than this is packed with 16 KB blocks; see blockSize(). */
    public const int SMALL_DFOLIO_BYTES = 16 * 1024 * 1024;
    /** item columns that hold a JSON document; stored as VARIANT. */
    public const array DOCUMENT_COLUMNS = ['dto_data', 'extras', 'raw'];
    /** Name of the source SQLite folio while DuckDB has it attached. */
    private const string SOURCE = 'folio_source';
    public const string DEFAULT_MEMORY_LIMIT = '6GB';
    /** Bytes of source JSON per INSERT when materializing a DTO table; see the comment where it is used. */
    private const int DTO_BATCH_BYTES = 2 * 1024 * 1024;
    /** Types whose documents average under DTO_SMALL_DOC bytes take much larger batches (measured ~26% faster on museum rows). */
    private const int DTO_BATCH_BYTES_SMALL = 32 * 1024 * 1024;
    private const int DTO_SMALL_DOC = 64 * 1024;

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
        // A newspaper folio has issues: newspaper-typed items in its `doc` core, which is how the rend exporter finds them. A
        // museum collection can hold the odd newspaper-typed object (the Science Museum Group's railway advertisers) in its
        // object core; that must not send the whole folio down the rend path. Newspaper items are a tiny share of a real
        // newspaper folio (rappnews4909: 3,115 issues among 966,590 items), so a ratio is not a usable test.
        $newspaper = (bool) $source->query('SELECT 1 FROM item WHERE dto_type = '.$source->quote(ContentType::NEWSPAPER)." AND core_id LIKE '%:doc' LIMIT 1")->fetchColumn();
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
            // DuckDB reads the SQLite folio itself. Columns arrive as text and are cast to the declared types below, so
            // a value that does not fit its column fails the export instead of being guessed.
            $db->exec('LOAD sqlite_scanner');
            $db->exec('SET sqlite_all_varchar = true');
            $db->exec('ATTACH '.$db->quote($folio).' AS '.self::SOURCE.' (TYPE sqlite, READ_ONLY)');
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
                if (null === $publishRend || !in_array($table, ['item', 'page'], true)) {
                    // sqlite_query() rather than the attached table: it also returns generated columns (item.sort_key).
                    $casts = $names = [];
                    foreach ($shape as $name => $type) {
                        $names[] = self::identifier($name);
                        $casts[] = 'CAST('.self::identifier($name).' AS '.$type.') AS '.self::identifier($name);
                    }
                    $db->exec('CREATE TABLE '.self::identifier($table).' AS SELECT '.implode(', ', $casts).' FROM sqlite_query('.$db->quote(self::SOURCE).', '
                        .$db->quote('SELECT '.implode(', ', $names).' FROM '.self::identifier($table)).')');
                    $counts['main.'.$table] = (int) $db->query('SELECT count(*) FROM '.self::identifier($table))->fetchColumn();
                    self::linkOrdinal($db, $table, $shape);
                    continue;
                }
                // A newspaper's item and page rows lose their geometry to the rend, row by row, in PHP.
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
                self::linkOrdinal($db, $table, $shape);
            }
            // A newspaper's grouping is group rows and links; project them into tables a reader can prune by issue.
            if (isset($refs) || DuckDbGroupingProjection::hasGroups($db)) {
                $counts += DuckDbGroupingProjection::materialize($db);
            }
            if (0 === ($counts['main.item'] ?? 0)) {
                throw new \RuntimeException('Refusing to export an empty folio.');
            }
            self::embedPages($db);
            $db->exec('CREATE SCHEMA dto');
            $db->exec('CREATE TABLE _dto_schema (dto_type VARCHAR, dto_class VARCHAR, structure JSON)');
            foreach ($source->query('SELECT DISTINCT dto_type FROM item ORDER BY dto_type')->fetchAll(\PDO::FETCH_COLUMN) as $type) {
                $class = $this->types->classForType($type) ?? throw new \RuntimeException('No DTO contract for '.$type);
                $shape = $this->schema->structure($class);
                if (array_key_exists('_itemId', $shape) || array_key_exists('_coreId', $shape)) {
                    throw new \LogicException('DTO uses a reserved identity column.');
                }
                $structure = json_encode($shape, JSON_THROW_ON_ERROR);
                // Sparse DTO serialization omits empty fields; explicitly supply SQL NULL. One json_merge_patch over the document
                // pads every absent key with null in a single pass, instead of one json_extract (a document scan) per field.
                // A merge patch deletes keys the document sets to null explicitly, which would leave them absent, so a type
                // that has any ':null' in its documents (or none of its shape to pad) takes the per-field extraction instead.
                $explicitNull = (int) $db->query('SELECT count(*) FROM item WHERE dto_type = '.$db->quote($type)." AND contains(dto_data, ':null')")->fetchColumn() > 0;
                if (!$explicitNull) {
                    $payload = 'json_merge_patch(CAST('.$db->quote(json_encode(array_fill_keys(array_keys($shape), null), JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT)).' AS JSON), CAST(dto_data AS JSON))';
                    foreach ($shape as $name => $fieldType) {
                        if (!is_array($fieldType)) {
                            continue;
                        }
                        $pointer = $db->quote('/'.str_replace(['~', '/'], ['~0', '~1'], $name));
                        // A list stored as a JSON-encoded string is parsed into the list.
                        $payload = 'json_merge_patch('.$payload.", CASE WHEN json_type(json_extract(dto_data, $pointer)) = 'VARCHAR' THEN json_object(".$db->quote($name).', CAST(json_extract_string(dto_data, '.$pointer.') AS JSON)) ELSE CAST(\'{}\' AS JSON) END)';
                    }
                } else {
                    $fields = [];
                    foreach ($shape as $name => $fieldType) {
                        $pointer = '/'.str_replace(['~', '/'], ['~0', '~1'], $name);
                        $value = 'json_extract(dto_data, '.$db->quote($pointer).')';
                        if (is_array($fieldType)) {
                            $value = 'CASE WHEN json_type('.$value.") = 'VARCHAR' THEN CAST(json_extract_string(dto_data, ".$db->quote($pointer).') AS JSON) ELSE '.$value.' END';
                        }
                        $fields[] = $db->quote($name).', '.$value;
                    }
                    $payload = 'json_object('.implode(', ', $fields).')';
                }
                $db->prepare('INSERT INTO _dto_schema VALUES (?, ?, ?)')->execute([$type, $class, $structure]);
                $table = 'dto.'.self::identifier($type);
                // json_object/json_transform_strict copy each source document several times per thread, so a type whose
                // rows are large (a newspaper issue carries its whole OCR text, up to ~1 MB) exhausts the memory limit in
                // one statement, and even in batches counted in rows. Fill the table in batches bounded by source bytes.
                $select = 'SELECT id AS "_itemId", core_id AS "_coreId", unnest(json_transform_strict('.$payload.', '.$db->quote($structure).')) FROM item WHERE dto_type = '.$db->quote($type);
                $db->exec('CREATE TABLE '.$table.' AS '.$select.' LIMIT 0');
                $averageDoc = (float) $db->query('SELECT avg(length(dto_data)) FROM item WHERE dto_type = '.$db->quote($type))->fetchColumn();
                $batchBytes = $averageDoc < self::DTO_SMALL_DOC ? self::DTO_BATCH_BYTES_SMALL : self::DTO_BATCH_BYTES;
                $batches = $db->query('SELECT min(rowid), max(rowid) FROM (SELECT rowid, floor(sum(length(dto_data)) OVER (ORDER BY rowid) / '.$batchBytes.') AS bucket FROM item WHERE dto_type = '.$db->quote($type).') GROUP BY bucket ORDER BY 1')->fetchAll(\PDO::FETCH_NUM);
                foreach ($batches as [$first, $last]) {
                    $db->exec('INSERT INTO '.$table.' '.$select.' AND rowid BETWEEN '.(int) $first.' AND '.(int) $last);
                }
                $counts['dto.'.$type] = (int) $db->query('SELECT count(*) FROM '.$table)->fetchColumn();
            }
            // A detail page reads one item and its pages. Zone maps keep the first 8 bytes of a string and every id starts
            // with the folio code, so without a key the lookup scans the whole column. Added after the load, so that
            // the key is built once instead of checked for every batch.
            // The documents are stored as VARIANT: DuckDB splits them into typed sub-columns, so a reader that asks for one
            // field reads that field, and the file is smaller. Converted last, because the steps above read them as JSON text.
            foreach (self::DOCUMENT_COLUMNS as $column) {
                if ((bool) $db->query("SELECT count(*) FROM information_schema.columns WHERE table_catalog = current_database() AND table_schema = 'main' AND table_name = 'item' AND column_name = ".$db->quote($column))->fetchColumn()) {
                    $db->exec('ALTER TABLE item ALTER COLUMN '.self::identifier($column).' SET DATA TYPE VARIANT USING CAST(CAST('.self::identifier($column).' AS JSON) AS VARIANT)');
                }
            }
            $db->exec('ALTER TABLE item ADD PRIMARY KEY (id)');
            if ((bool) $db->query("SELECT count(*) FROM information_schema.columns WHERE table_catalog = current_database() AND table_schema = 'main' AND table_name = 'page' AND column_name = 'row_id'")->fetchColumn()) {
                $db->exec('CREATE INDEX page_row_id ON page (row_id)');
            }
            $db->commit();
            $db->exec('DETACH '.self::SOURCE);
            $db->exec('CHECKPOINT');
            // A dfolio is read-only once published, so finish it like an archive: copy it into a new file. The copy drops
            // the blocks that the updates above left unused, and it is the only point where the block size can follow the
            // size of the data (DuckDB fixes it when a file is created).
            $built = $directory.'/snapshot.dfolio';
            $packed = $directory.'/packed.dfolio';
            $db->exec('ATTACH '.$db->quote($packed).' AS packed (BLOCK_SIZE '.self::blockSize((int) filesize($built)).')');
            $db->exec('COPY FROM DATABASE '.self::identifier((string) $db->query('SELECT current_database()')->fetchColumn()).' TO packed');
            $db->exec('CHECKPOINT packed');
            $db->exec('DETACH packed');
            $db = null;
            $source->commit();
            if (is_file($built.'.wal') || is_file($packed.'.wal')) {
                throw new \RuntimeException('DuckDB export has an unmerged WAL.');
            }
            $fs->rename($packed, $target, overwrite: true);

            return ['path' => $target, 'buildId' => $identity[0]['build_id'], 'rows' => $counts['main.item'], 'counts' => $counts];
        } finally {
            $db = null;
            $source = null;
            $fs->remove($directory);
        }
    }

    /**
     * DuckDB's default 256 KB block gives every file a floor of about 6 MB, which is most of a small dfolio.
     * Measured on four datasets: 16 KB blocks are smallest for a file under that floor's range, 64 KB for larger ones.
     *
     * @param int $bytes size of the dfolio as built with the default block size
     */
    public static function blockSize(int $bytes): int
    {
        return $bytes < self::SMALL_DFOLIO_BYTES ? 16384 : 65536;
    }

    /** @param array<string,string> $shape */
    private static function linkOrdinal(\PDO $db, string $table, array $shape): void
    {
        if ('link' === $table && !array_key_exists('ordinal', $shape)) {
            $db->exec('ALTER TABLE link ADD COLUMN ordinal BIGINT');
            if (array_key_exists('extras', $shape)) {
                $db->exec("UPDATE link SET ordinal = CAST(json_extract_string(extras, '$.ordinal') AS BIGINT)");
            }
        } elseif ('link' === $table && array_key_exists('extras', $shape)) {
            // The source folio has the typed column, but its links may have been ingested before ingest filled it.
            $db->exec("UPDATE link SET ordinal = CAST(json_extract_string(extras, '$.ordinal') AS BIGINT) WHERE ordinal IS NULL AND json_extract_string(extras, '$.ordinal') IS NOT NULL");
        }
    }

    /** Row fields that some sources fill with the address of the row's own page image. */
    public const array PAGE_COPY_FIELDS = [ItemField::THUMBNAIL_URL, ItemField::LARGE_IMAGE_URL, ItemField::IIIF_BASE];

    /**
     * Put each item's pages on the item, in page order, so that one read returns a record with its images.
     * A row image field that only repeats one of those pages (its url, its source, or the public address of
     * its s3:// copy) is removed from dto_data: the page is the image, and the copy is often stale.
     * A field with any other value is kept.
     */
    public static function embedPages(\PDO $db): void
    {
        $columns = $db->query("SELECT column_name FROM information_schema.columns WHERE table_catalog = current_database() AND table_schema = 'main' AND table_name = 'page' ORDER BY ordinal_position")->fetchAll(\PDO::FETCH_COLUMN);
        if (!in_array('row_id', $columns, true)) {
            return;
        }
        $fields = array_values(array_diff($columns, ['row_id']));
        $struct = 'struct_pack('.implode(', ', array_map(static fn (string $c): string => self::identifier($c).' := '.self::identifier($c), $fields)).')';
        $order = implode(', ', array_map(self::identifier(...), array_values(array_intersect(['seq', 'id'], $fields))));
        $pages = 'list('.$struct.('' === $order ? '' : ' ORDER BY '.$order).')';
        $db->exec('ALTER TABLE item ADD COLUMN pages '.$db->query('SELECT typeof('.$pages.') FROM page')->fetchColumn());
        $db->exec('UPDATE item SET pages = g.pages FROM (SELECT row_id, '.$pages.' AS pages FROM page GROUP BY row_id) g WHERE item.id = g.row_id');
        self::removePageCopies($db, $fields);
    }

    /**
     * Runs while dto_data is still JSON text.
     *
     * @param list<string> $fields the page columns now on item.pages
     */
    private static function removePageCopies(\PDO $db, array $fields): void
    {
        if (!in_array('url', $fields, true) || 'VARCHAR' !== $db->query("SELECT data_type FROM information_schema.columns WHERE table_catalog = current_database() AND table_schema = 'main' AND table_name = 'item' AND column_name = 'dto_data'")->fetchColumn()) {
            return;
        }
        $source = in_array('source_url', $fields, true) ? ' OR v = pg.source_url' : '';
        $repeats = static fn (string $value): string => 'len(list_filter(pages, lambda pg: '.str_replace('v', $value,
            "v = pg.url$source OR (starts_with(pg.url, 's3://') AND ends_with(v, substr(pg.url, 5)))").')) > 0';
        $patch = $any = [];
        foreach (self::PAGE_COPY_FIELDS as $field) {
            $value = 'json_extract_string(dto_data, '.$db->quote('$.'.$field).')';
            $any[] = $repeats($value);
            // A JSON null in a merge patch removes the key.
            $patch[] = $db->quote($field).', CASE WHEN '.$repeats($value).' THEN NULL ELSE json_extract(dto_data, '.$db->quote('$.'.$field).') END';
        }
        $db->exec('UPDATE item SET dto_data = CAST(json_merge_patch(CAST(dto_data AS JSON), json_object('.implode(', ', $patch).')) AS VARCHAR) WHERE pages IS NOT NULL AND ('.implode(' OR ', $any).')');
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
