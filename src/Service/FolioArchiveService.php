<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

final readonly class FolioArchiveService
{
    public function __construct(
        private FolioService $folios,
        private FolioFtsIndexer $ftsIndexer,
        private FolioArchivePreparer $preparer,
        private FolioViewBuilder $viewBuilder,
    ) {
    }

    /**
     * @return array{source:string,archive:string,sourceBytes:int,archiveBytes:int}
     */
    public function archive(string $folioCode, ?string $archivePath = null): array
    {
        $source = $this->folios->path($folioCode);
        if (!is_file($source)) {
            throw new \RuntimeException(sprintf('Folio database not found: %s', $source));
        }

        $this->preparer->prepare($source, ['archivePreparedAt' => gmdate(DATE_ATOM)]);

        $archivePath ??= $source . '.gz';
        $dir = dirname($archivePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create archive directory "%s".', $dir));
        }

        $tmp = $archivePath . '.tmp.sqlite';
        if (!copy($source, $tmp)) {
            throw new \RuntimeException(sprintf('Unable to create archive staging copy "%s".', $tmp));
        }

        $pdo = new \PDO('sqlite:' . $tmp);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

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

        $this->gzip($tmp, $archivePath);
        unlink($tmp);

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
    public function restore(string $archivePath, string $folioCode, bool $force = false): array
    {
        if (!is_file($archivePath)) {
            throw new \RuntimeException(sprintf('Folio archive not found: %s', $archivePath));
        }

        $target = $this->folios->path($folioCode, createDirectory: true);
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

        // Rebuild FTS
        $tFts = microtime(true);
        $fts = $this->ftsIndexer->rebuild($dbFile);
        $ftsTime = microtime(true) - $tFts;

        return [
            'indexes' => $indexes,
            'views' => $views['views'] ?? 0,
            'ftsRows' => $fts['rows'] ?? 0,
            'timing' => ['indexes' => $indexesTime, 'views' => $viewsTime, 'fts' => $ftsTime],
        ];
    }

    private function gzip(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb9');
        if (!$in || !$out) {
            throw new \RuntimeException(sprintf('Unable to gzip "%s" to "%s".', $source, $target));
        }

        while (!feof($in)) {
            gzwrite($out, fread($in, 1048576));
        }

        fclose($in);
        gzclose($out);
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
