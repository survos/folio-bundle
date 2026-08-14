<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Psr\Log\LoggerInterface;

/**
 * The ONLY code in this codebase allowed to write into folio_archive.storage (an rclone mount of
 * the shared S3 bucket, see zm/mono/harvest's config/packages/flysystem.yaml) -- new archive
 * plumbing must go through archive() below, not fopen()/gzopen()/sqlite `ATTACH` directly on an
 * archive path. Every SQLite/gzip step happens in a local staging dir first; publishToMount() is
 * the single, timed, logged copy() that actually crosses to the mount. This is deliberate: a raw
 * write stream (gzopen($mountPath, 'wb') + a loop of gzwrite() calls) left a partial file visible
 * on S3 for the whole duration of the write if interrupted; a copy() of an already-complete local
 * file is one atomic-from-the-caller's-perspective operation.
 */
final readonly class FolioArchiveService
{
    public function __construct(
        private FolioService $folios,
        private FolioFtsIndexer $ftsIndexer,
        private FolioArchivePreparer $preparer,
        private FolioViewBuilder $viewBuilder,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array{source:string,archive:string,sourceBytes:int,archiveBytes:int}
     */
    public function archive(string $folioCode, ?string $archivePath = null, ?string $locale = null): array
    {
        $source = $this->folios->path($folioCode, locale: $locale);
        if (!is_file($source)) {
            throw new \RuntimeException(sprintf('Folio database not found: %s', $source));
        }

        $this->preparer->prepare($source, ['archivePreparedAt' => gmdate(DATE_ATOM)]);

        $archivePath ??= $source . '.gz';

        // Everything up to the final publish happens in a LOCAL staging dir -- never on the
        // mount. Besides gating writes, this is also just faster: the SQLite DDL/VACUUM below is
        // many small round trips, which used to each cross the FUSE mount when $tmp lived next to
        // $archivePath.
        $stagingDir = sys_get_temp_dir() . '/folio-archive-staging/' . bin2hex(random_bytes(8));
        if (!mkdir($stagingDir, 0775, true) && !is_dir($stagingDir)) {
            throw new \RuntimeException(sprintf('Unable to create local staging directory "%s".', $stagingDir));
        }

        try {
            $localGz = $stagingDir . '/' . basename($archivePath);
            $this->buildLocalArchive($source, $stagingDir, $localGz);
            $this->publishToMount($localGz, $archivePath);
        } finally {
            $this->removeDirectory($stagingDir);
        }

        return [
            'source' => $source,
            'archive' => $archivePath,
            'sourceBytes' => filesize($source) ?: 0,
            'archiveBytes' => filesize($archivePath) ?: 0,
        ];
    }

    /**
     * Prepares and gzips $source entirely within $stagingDir (local disk), producing $localGz.
     * Never touches folio_archive.storage -- see the class docblock.
     */
    private function buildLocalArchive(string $source, string $stagingDir, string $localGz): void
    {
        // Fold the WAL into the main file before the copy: copy() takes only the
        // .folio bytes, not the -wal sidecar, and SQLite auto-checkpoints just every
        // ~1000 WAL pages — so without this the tail of recent commits (rows, the
        // archivePreparedAt just written above) can be missing from the archive.
        $this->checkpoint($source);

        $tmp = $stagingDir . '/' . basename($source) . '.tmp.sqlite';
        if (!copy($source, $tmp)) {
            throw new \RuntimeException(sprintf('Unable to create archive staging copy "%s".', $tmp));
        }

        $pdo = new \PDO('sqlite:' . $tmp);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // The working folio runs in WAL mode (faster bulk ingest); checkpoint() above only
        // flushes WAL frames into the main file, it does NOT clear the "this db is WAL-mode"
        // flag in the file header (bytes 18-19). A client that loads the archive via
        // sqlite3_deserialize() (no real filesystem, so no -wal/-shm sidecars can exist) sees
        // that flag, tries to open a WAL companion that isn't there, and fails with
        // SQLITE_CANTOPEN. Switching to DELETE here both checkpoints (redundant after the one
        // above, cheap) AND rewrites the header back to plain rollback-journal mode, so the
        // shipped archive is fully self-contained and safe to deserialize anywhere. Doing this
        // BEFORE the DDL/VACUUM below also keeps the whole staging step off WAL, so nothing
        // re-creates a -wal file while we're building the archive.
        $pdo->exec('PRAGMA journal_mode = DELETE');

        // Drop derived search/facet tables; they are recreated during inflate.
        foreach (['item_facet', 'item_facet_count'] as $derivedTable) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($derivedTable));
        }

        // Drop all FTS5 tables (shadow tables auto-drop with the main virtual table)
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND sql LIKE '%fts5%'")->fetchAll(\PDO::FETCH_COLUMN) as $fts) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($fts));
        }
        // Drop fts5vocab tables
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND sql LIKE '%fts5vocab%'")->fetchAll(\PDO::FETCH_COLUMN) as $vocab) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->quote($vocab));
        }

        // Drop all user-created indexes (keep sqlite_autoindex_* which enforce UNIQUE constraints)
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'index' AND name NOT LIKE 'sqlite_%' AND sql IS NOT NULL")->fetchAll(\PDO::FETCH_COLUMN) as $idx) {
            $pdo->exec('DROP INDEX IF EXISTS ' . $this->quote($idx));
        }

        // Drop generated views
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'view'")->fetchAll(\PDO::FETCH_COLUMN) as $view) {
            $pdo->exec('DROP VIEW IF EXISTS ' . $this->quote($view));
        }

        $pdo->exec('VACUUM');
        unset($pdo);

        // Belt-and-suspenders: DELETE-mode + a clean connection close above should never leave
        // sidecars, but gzip() only reads $tmp itself -- a stray -wal/-shm would otherwise be
        // silently left on disk (not in the archive, but not cleaned up either) if anything
        // upstream ever changes. Confirm there's nothing to ship before we do.
        foreach ([$tmp . '-wal', $tmp . '-shm'] as $sidecar) {
            if (is_file($sidecar)) {
                unlink($sidecar);
            }
        }

        $this->gzip($tmp, $localGz);
        unlink($tmp);
    }

    /**
     * The one place a finished archive crosses onto folio_archive.storage -- a single copy() of
     * an already-complete local file, timed and logged. See the class docblock.
     */
    private function publishToMount(string $localGz, string $archivePath): void
    {
        $dir = dirname($archivePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create archive directory "%s".', $dir));
        }

        $bytes = filesize($localGz) ?: 0;
        $start = microtime(true);
        if (!copy($localGz, $archivePath)) {
            throw new \RuntimeException(sprintf('Unable to publish archive to "%s".', $archivePath));
        }
        $elapsed = microtime(true) - $start;

        $this->logger?->info('Folio archive published to mount', [
            'archive' => $archivePath,
            'bytes' => $bytes,
            'seconds' => round($elapsed, 3),
            'bytesPerSecond' => $elapsed > 0 ? (int) round($bytes / $elapsed) : null,
        ]);
    }

    /**
     * Checkpoint and copy the just-ingested, pre-inflate working folio to its durable bare
     * snapshot location (folio-bare/<provider>/<code>.folio) -- captured once, right after row
     * ingest, before inflate() adds indexes/FTS5/views. compressBare() later gzips this file
     * directly on first request, so lazy archive creation never has to strip indexes back out
     * or VACUUM a large working database.
     */
    public function snapshotBare(string $folioCode, string $workingPath, ?string $locale = null): string
    {
        $this->checkpoint($workingPath);

        $target = $this->folios->barePath($folioCode, createDirectory: true, locale: $locale);
        if (!copy($workingPath, $target)) {
            throw new \RuntimeException(sprintf('Unable to snapshot bare folio to "%s".', $target));
        }

        return $target;
    }

    /**
     * Compress the bare (pre-inflate) snapshot straight to a `.gz` archive -- no checkpoint, no
     * index/FTS/view stripping, no VACUUM, since the bare snapshot was already clean when
     * snapshotBare() captured it. This is the lazy, on-request counterpart to archive(): cheap
     * enough to run inline in an HTTP request instead of needing a background job.
     *
     * @return array{source:string,archive:string,sourceBytes:int,archiveBytes:int}
     */
    public function compressBare(string $folioCode, ?string $archivePath = null, ?string $locale = null): array
    {
        $source = $this->folios->barePath($folioCode, locale: $locale);
        if (!is_file($source)) {
            throw new \RuntimeException(sprintf('Bare folio snapshot not found: %s', $source));
        }

        $archivePath ??= $this->folios->archivePath($folioCode, locale: $locale);

        // Same local-staging-then-publish pattern as archive() (see class docblock): gzip into a
        // local temp dir, then one timed/logged copy() onto the (possibly mounted) archive path.
        $stagingDir = sys_get_temp_dir() . '/folio-archive-staging/' . bin2hex(random_bytes(8));
        if (!mkdir($stagingDir, 0775, true) && !is_dir($stagingDir)) {
            throw new \RuntimeException(sprintf('Unable to create local staging directory "%s".', $stagingDir));
        }

        try {
            $localGz = $stagingDir . '/' . basename($archivePath);
            $this->gzip($source, $localGz);
            $this->publishToMount($localGz, $archivePath);
        } finally {
            $this->removeDirectory($stagingDir);
        }

        return [
            'source' => $source,
            'archive' => $archivePath,
            'sourceBytes' => filesize($source) ?: 0,
            'archiveBytes' => filesize($archivePath) ?: 0,
        ];
    }

    /**
     * @return array{archive:string,target:string,targetBytes:int,indexedRows:int}
     */
    public function restore(string $archivePath, string $folioCode, bool $force = false, ?string $locale = null): array
    {
        if (!is_file($archivePath)) {
            throw new \RuntimeException(sprintf('Folio archive not found: %s', $archivePath));
        }

        $target = $this->folios->path($folioCode, createDirectory: true, locale: $locale);
        if (is_file($target) && !$force) {
            throw new \RuntimeException(sprintf('Target folio already exists: %s. Use --force to replace it.', $target));
        }

        $this->gunzip($archivePath, $target);
        $inflated = $this->inflate($target);

        return [
            'archive' => $archivePath,
            'target' => $target,
            'targetBytes' => filesize($target) ?: 0,
            'indexedRows' => $inflated['ftsRows'],
        ];
    }

    /**
     * Inflate a deflated folio: recreate indexes, rebuild FTS, rebuild views.
     * @return array{indexes:int,views:int,ftsRows:int}
     */
    public function inflate(string $dbFile): array
    {
        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Recreate standard indexes
        $indexDefs = [
            'CREATE INDEX IF NOT EXISTS idx_item_label ON item(label)',
            'CREATE INDEX IF NOT EXISTS idx_item_dto_type ON item(dto_type)',
            'CREATE INDEX IF NOT EXISTS idx_item_core_dto_type ON item(core_id, dto_type)',
            "CREATE INDEX IF NOT EXISTS idx_item_json_creator ON item(json_extract(dto_data, '$.creator'))",
            "CREATE INDEX IF NOT EXISTS idx_item_json_date ON item(json_extract(dto_data, '$.date'))",
            "CREATE INDEX IF NOT EXISTS idx_item_json_year ON item(json_extract(dto_data, '$.year'))",
            "CREATE INDEX IF NOT EXISTS idx_item_json_contenttype ON item(json_extract(dto_data, '$.contentType'))",
            "CREATE INDEX IF NOT EXISTS idx_item_json_language ON item(json_extract(dto_data, '$.language'))",
            'CREATE INDEX IF NOT EXISTS idx_link_left ON link(left_core, left_id)',
            'CREATE INDEX IF NOT EXISTS idx_link_right ON link(right_core, right_id)',
            'CREATE INDEX IF NOT EXISTS idx_term_path ON term(term_set_id, path)',
            'CREATE INDEX IF NOT EXISTS idx_schema_property_name ON schema_property(name)',
            'CREATE INDEX IF NOT EXISTS idx_schema_table_core ON schema_table(core_code)',
            'CREATE INDEX IF NOT EXISTS idx_docs_type ON docs(type)',
        ];
        $indexes = 0;
        $tIndexes = microtime(true);
        foreach ($indexDefs as $sql) {
            $pdo->exec($sql);
            $indexes++;
        }
        $indexesTime = microtime(true) - $tIndexes;
        unset($pdo);

        // Rebuild views from schema_property
        $tViews = microtime(true);
        $views = $this->viewBuilder->rebuild($dbFile);
        $viewsTime = microtime(true) - $tViews;

        // Rebuild FTS -- also ensures 'sort_key' exists (FolioFtsIndexer::createBrowseIndexes()),
        // so it doesn't need its own separate call here too.
        $tFts = microtime(true);
        $fts = $this->ftsIndexer->rebuild($dbFile);
        $ftsTime = microtime(true) - $tFts;

        // Leave the working folio self-contained: the index/view/FTS rebuilds above
        // each left frames in the -wal. Checkpoint so a copy/upload — or a reader on
        // a fresh connection — never sees a torn db missing the latest derived tables.
        $this->checkpoint($dbFile);

        return [
            'indexes' => $indexes,
            'views' => $views['views'] ?? 0,
            'ftsRows' => $fts['rows'] ?? 0,
            'timing' => ['indexes' => $indexesTime, 'views' => $viewsTime, 'fts' => $ftsTime],
        ];
    }

    /**
     * Fold the WAL back into the main database file (TRUNCATE resets the -wal too),
     * so a subsequent file-level copy or a shipped/served folio is self-contained.
     * SQLite only auto-checkpoints every ~1000 WAL pages, so the tail of recent
     * commits can otherwise sit unflushed in the -wal sidecar and be lost on copy.
     */
    private function checkpoint(string $dbFile): void
    {
        if (!is_file($dbFile)) {
            return;
        }

        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 30000');
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        unset($pdo);
    }

    /**
     * Level defaults to 6 (zlib's own default), not 9 (max): 9 buys a modest size reduction
     * over 6 for a lot more CPU time, and disk space is cheap here -- speed matters more,
     * especially for compressBare()'s on-request, block-a-live-HTTP-request use.
     */
    private function gzip(string $source, string $target, int $level = 6): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb' . $level);
        if (!$in || !$out) {
            throw new \RuntimeException(sprintf('Unable to gzip "%s" to "%s".', $source, $target));
        }

        while (!feof($in)) {
            gzwrite($out, fread($in, 1048576));
        }

        fclose($in);
        gzclose($out);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function gunzip(string $source, string $target): void
    {
        $in = gzopen($source, 'rb');
        $out = fopen($target, 'wb');
        if (!$in || !$out) {
            throw new \RuntimeException(sprintf('Unable to expand "%s" to "%s".', $source, $target));
        }

        while (!gzeof($in)) {
            fwrite($out, gzread($in, 1048576));
        }

        gzclose($in);
        fclose($out);
    }
}
