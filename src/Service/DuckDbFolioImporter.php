<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/**
 * Rebuild a SQLite folio from a .dfolio: the reverse of {@see DuckDbFolioExporter}.
 *
 * The .dfolio's `main` schema keeps every logical SQLite table, so the schema (columns, keys) comes from the
 * bootstrap template and the rows come from DuckDB. Generated columns are skipped, as are FTS/internal tables.
 * Newspaper geometry lives in the .rend, not the .dfolio, so a rebuilt newspaper folio has no block geometry
 * unless a caller supplies it. This is for regenerating a `.folio` / `.folio.gz` archive on demand.
 */
final class DuckDbFolioImporter
{
    private const int BATCH = 1000;
    /** DuckDB-only tables that have no SQLite counterpart. */
    private const array DUCK_ONLY = ['_renditions', '_dto_schema'];

    public function __construct(private readonly FolioService $folios)
    {
    }

    /** @return array{path:string,counts:array<string,int>} */
    public function import(string $dfolio, string $target): array
    {
        if (!is_file($dfolio) || !DuckDbFolioFile::accepts($dfolio)) {
            throw new \InvalidArgumentException('Supply an existing .dfolio or .duckdb file.');
        }
        $this->folios->createEmptyAt($target);
        $duck = new \PDO('duckdb:'.$dfolio, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $sqlite = new \PDO('sqlite:'.$target, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $counts = [];
        try {
            $sqlite->exec('PRAGMA synchronous = OFF');
            $sqlite->exec('PRAGMA foreign_keys = OFF');
            $sqlite->beginTransaction();
            // Created by FolioBuilder at build time, not part of the bootstrap template.
            $sqlite->exec('CREATE TABLE IF NOT EXISTS _folio_build (build_id TEXT NOT NULL, schema_version INTEGER NOT NULL)');
            $have = [];
            foreach (DuckDbFolioExporter::tables($sqlite) as $table) {
                $have[$table] = $this->insertableColumns($sqlite, $table);
            }
            $tables = $duck->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'main' ORDER BY table_name")->fetchAll(\PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                if (in_array($table, self::DUCK_ONLY, true) || !isset($have[$table])) {
                    continue;
                }
                // The template can predate columns the producing build added (e.g. item.sort_key): add what the .dfolio carries.
                $types = $duck->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = 'main' AND table_name = ".$duck->quote($table))->fetchAll(\PDO::FETCH_KEY_PAIR);
                foreach ($types as $name => $type) {
                    if (!in_array($name, $have[$table], true) && !$this->isGenerated($sqlite, $table, $name)) {
                        $sqlite->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', DuckDbFolioExporter::identifier($table), DuckDbFolioExporter::identifier($name),
                            match (true) {
                                str_contains($type, 'INT') || 'BOOLEAN' === $type => 'INTEGER', in_array($type, ['DOUBLE', 'FLOAT'], true) => 'REAL', default => 'TEXT',
                            }));
                        $have[$table][] = $name;
                    }
                }
                $sqlite->exec('DELETE FROM '.DuckDbFolioExporter::identifier($table));
                $columns = array_values(array_intersect($have[$table], array_keys($types)));
                $insert = $sqlite->prepare(sprintf(
                    'INSERT INTO %s (%s) VALUES (%s)',
                    DuckDbFolioExporter::identifier($table),
                    implode(', ', array_map(DuckDbFolioExporter::identifier(...), $columns)),
                    implode(', ', array_fill(0, count($columns), '?')),
                ));
                $select = $duck->query('SELECT '.implode(', ', array_map(DuckDbFolioExporter::identifier(...), $columns)).' FROM main.'.DuckDbFolioExporter::identifier($table));
                $count = 0;
                $select->setFetchMode(\PDO::FETCH_NUM);
                foreach ($select as $row) {
                    $insert->execute(array_map(static fn (mixed $v): mixed => is_bool($v) ? (int) $v : $v, $row));
                    ++$count;
                }
                $counts[$table] = $count;
            }
            $sqlite->commit();
        } catch (\Throwable $e) {
            if ($sqlite->inTransaction()) {
                $sqlite->rollBack();
            }
            @unlink($target);
            throw $e;
        }

        return ['path' => $target, 'counts' => $counts];
    }

    private function isGenerated(\PDO $sqlite, string $table, string $column): bool
    {
        foreach ($sqlite->query('PRAGMA table_xinfo('.DuckDbFolioExporter::identifier($table).')', \PDO::FETCH_ASSOC) as $c) {
            if ($c['name'] === $column) {
                return 0 !== (int) $c['hidden'];
            }
        }

        return false;
    }

    /** Columns that accept an INSERT: real, non-generated, non-hidden. Only those the .dfolio also carries are selected. */
    private function insertableColumns(\PDO $sqlite, string $table): array
    {
        $columns = [];
        foreach ($sqlite->query('PRAGMA table_xinfo('.DuckDbFolioExporter::identifier($table).')', \PDO::FETCH_ASSOC) as $column) {
            if (0 === (int) $column['hidden']) {
                $columns[] = $column['name'];
            }
        }

        return $columns;
    }
}
