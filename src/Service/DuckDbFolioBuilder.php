<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Dto\FolioBuildInput;

/**
 * Optional source-language analytical snapshot of explicit JSONL inputs.
 *
 * Main tables are row cores; aux/translation contain the supplied supporting streams.
 * raw_core/raw_aux/raw_translation retain original JSON, including missing vs null keys.
 * This is not the SQLite reader schema or a replacement for its DTO/translation logic.
 * Requires ext-pdo_duckdb with DuckDB 2.0; no network publication or dataset discovery.
 */
final class DuckDbFolioBuilder
{
    /** @return array{path:string,rows:int,counts:array<string,int>} */
    public function build(FolioBuildInput $input, string $target): array
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('DuckDB snapshots require ext-pdo_duckdb (DuckDB 2.0).');
        }
        if (null !== $input->locale && $input->locale !== $input->sourceLocale) {
            throw new \InvalidArgumentException('DuckDB snapshots consume source-language inputs; translated folio builds are not supported.');
        }
        if (!str_ends_with($target, '.duckdb') || !is_dir(dirname($target))) {
            throw new \InvalidArgumentException('Use a .duckdb target in an existing local directory.');
        }

        $directory = dirname($target).'/.duckdb-build-'.bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new \RuntimeException('Cannot create DuckDB build directory: '.$directory);
        }
        $temporary = $directory.'/snapshot.duckdb';
        $db = null;
        try {
            $db = new \PDO('duckdb:'.$temporary, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $version = (string) $db->query('SELECT version()')->fetchColumn();
            if (!str_starts_with($version, 'v2.')) {
                throw new \RuntimeException('DuckDB 2.x is required; loaded '.$version);
            }
            $db->beginTransaction();
            $db->exec('CREATE TABLE _folio (code VARCHAR, metadata JSON, source_locale VARCHAR, engine_version VARCHAR)');
            $db->prepare('INSERT INTO _folio VALUES (?, ?, ?, ?)')->execute([
                $input->code, json_encode($input->metadata, JSON_THROW_ON_ERROR), $input->sourceLocale, $version,
            ]);
            $db->exec('CREATE TABLE _streams (schema_name VARCHAR, stream_name VARCHAR, input_path VARCHAR, row_count BIGINT)');

            $counts = [];
            $rows = 0;
            $groups = ['core' => $input->sources, 'aux' => $input->files, 'translation' => $input->translations];
            if (null !== $input->reviewedTranslationFile) {
                if (array_key_exists('reviewedTranslation', $groups['aux'])) {
                    throw new \InvalidArgumentException('Duplicate reviewedTranslation stream.');
                }
                $groups['aux']['reviewedTranslation'] = $input->reviewedTranslationFile;
            }
            foreach ($groups as $group => $files) {
                $schema = 'core' === $group ? 'main' : $group;
                $rawSchema = 'raw_'.$group;
                $db->exec('CREATE SCHEMA IF NOT EXISTS '.self::identifier($schema));
                $db->exec('CREATE SCHEMA '.self::identifier($rawSchema));
                foreach ($files as $name => $file) {
                    if (str_starts_with($name, '_')) {
                        throw new \InvalidArgumentException('Stream names starting with _ are reserved: '.$name);
                    }
                    $rawTable = self::identifier($rawSchema).'.'.self::identifier($name);
                    $table = self::identifier($schema).'.'.self::identifier($name);
                    $db->exec('CREATE TABLE '.$rawTable.' AS SELECT json FROM read_ndjson_objects('.$db->quote($file).')');
                    // Scan every object: late/sparse keys and heterogeneous nested values survive.
                    [$count, $invalid, $shape] = $db->query('SELECT count(*), count(*) FILTER (WHERE json_type(json) IS DISTINCT FROM \'OBJECT\'), json_group_structure(json)::VARCHAR FROM '.$rawTable)->fetch(\PDO::FETCH_NUM);
                    if (0 !== $invalid) {
                        throw new \RuntimeException('JSONL stream must contain objects: '.$file);
                    }
                    if (0 === $count || '{}' === $shape) {
                        // An empty stream has no inferable schema; retain its JSON column.
                        $db->exec('CREATE TABLE '.$table.' AS SELECT * FROM '.$rawTable);
                    } else {
                        // Sparse optional keys become SQL NULL; raw JSON retains absence vs null.
                        // The schema comes from every value in this same table, not a sample.
                        $db->exec('CREATE TABLE '.$table.' AS SELECT unnest(json_transform(json, '.$db->quote($shape).')) FROM '.$rawTable);
                    }
                    $actual = (int) $db->query('SELECT count(*) FROM '.$table)->fetchColumn();
                    if ($count !== $actual) {
                        throw new \RuntimeException('DuckDB row-count mismatch: '.$file);
                    }
                    $counts[$schema.'.'.$name] = $actual;
                    if ('core' === $group) {
                        $rows += $actual;
                    }
                    $db->prepare('INSERT INTO _streams VALUES (?, ?, ?, ?)')->execute([$schema, $name, $file, $actual]);
                }
            }
            if (0 === $rows) {
                throw new \RuntimeException('Refusing to publish an empty DuckDB folio: '.$input->code);
            }
            $db->commit();
            $db->exec('CHECKPOINT');
            $db = null;
            if (is_file($temporary.'.wal')) {
                throw new \RuntimeException('DuckDB build still has an unmerged WAL.');
            }
            if (!rename($temporary, $target)) {
                throw new \RuntimeException('Cannot install DuckDB snapshot: '.$target);
            }

            return ['path' => $target, 'rows' => $rows, 'counts' => $counts];
        } finally {
            $db = null;
            // Only remove files owned by this unique, private build directory.
            foreach ([$temporary.'.wal', $temporary] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }

    private static function identifier(string $name): string
    {
        if ('' === $name || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Invalid DuckDB identifier.');
        }

        return '"'.str_replace('"', '""', $name).'"';
    }
}
