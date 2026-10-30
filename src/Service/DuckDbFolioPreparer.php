<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\SearchBundle\Adapter\DuckDb\DuckDbFtsIndexer;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Rebuildable companion of a restored folio; independent of Harvest's ingestion pipeline. */
final readonly class DuckDbFolioPreparer
{
    public function __construct(
        private DuckDbFolioExporter $exporter = new DuckDbFolioExporter(),
        private DuckDbFolioSearchProjection $projection = new DuckDbFolioSearchProjection(),
        private FolioDtoTypeResolver $types = new FolioDtoTypeResolver(),
        private DuckDbDtoSchema $schema = new DuckDbDtoSchema(),
    ) {
    }

    /** @return array{path:string,buildId:string,rows:int,reused:bool} */
    public function prepare(string $folio, ?string $target = null, bool $fts = false, bool $force = false): array
    {
        $folio = realpath($folio) ?: throw new \InvalidArgumentException('Folio does not exist.');
        $target ??= substr($folio, 0, -strlen('.folio')).'.duckdb';
        if (!str_ends_with($folio, '.folio') || !str_ends_with($target, '.duckdb') || str_contains($target, '://')) {
            throw new \InvalidArgumentException('Preparation requires local .folio and .duckdb paths.');
        }
        $fs = new Filesystem();
        $fs->mkdir(dirname($target));
        $target = realpath(dirname($target)).'/'.basename($target);
        $lock = (new LockFactory(new FlockStore()))->createLock('folio-duckdb:'.$target, ttl: null);
        if (!$lock->acquire()) {
            throw new \RuntimeException('This DuckDB companion is already being prepared.');
        }
        $temporary = dirname($target).'/.prepare-'.bin2hex(random_bytes(8)).'.duckdb';
        $db = null;
        try {
            [$buildId, $signature] = $this->identity($folio, $fts);
            if (!$force && is_file($target)) {
                try {
                    $db = (new DuckDbFolioReader())->open($target);
                    $meta = $db->query('SELECT * FROM _duckdb_preparation')->fetchAll(\PDO::FETCH_ASSOC);
                    $builds = $db->query('SELECT build_id FROM _folio_build')->fetchAll(\PDO::FETCH_COLUMN);
                    if (1 === count($meta) && [$buildId] === $builds && $meta[0]['build_id'] === $buildId && $meta[0]['signature'] === $signature) {
                        return ['path' => $target, 'buildId' => $buildId, 'rows' => (int) $db->query('SELECT count(*) FROM search_records')->fetchColumn(), 'reused' => true];
                    }
                } catch (\PDOException) {
                    // Old companions have no preparation manifest. Rebuild them without changing the archive.
                } finally {
                    $db = null;
                }
            }
            $result = $this->exporter->export($folio, $temporary);
            $db = new \PDO('duckdb:'.$temporary, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $db->beginTransaction();
            $rows = $this->projection->build($db, $fts);
            if ($rows !== $result['rows'] || $result['buildId'] !== $buildId || [$buildId, $signature] !== $this->identity($folio, $fts)) {
                throw new \RuntimeException('Folio changed during preparation or the search projection lost records. Retry preparation.');
            }
            $db->exec('CREATE TABLE _duckdb_preparation (build_id VARCHAR, signature VARCHAR, fts BOOLEAN)');
            $db->prepare('INSERT INTO _duckdb_preparation VALUES (?, ?, ?)')->execute([$buildId, $signature, $fts ? 1 : 0]);
            $db->commit();
            $db->exec('CHECKPOINT');
            $db = null;
            if (is_file($temporary.'.wal')) {
                throw new \RuntimeException('DuckDB preparation has an unmerged WAL.');
            }
            $fs->rename($temporary, $target, overwrite: true);

            return ['path' => $target, 'buildId' => $buildId, 'rows' => $rows, 'reused' => false];
        } finally {
            $db = null;
            $fs->remove([$temporary, $temporary.'.wal']);
            $lock->release();
        }
    }

    /** @return array{string,string} */
    private function identity(string $folio, bool $fts): array
    {
        $source = new \PDO('sqlite:file:'.$folio.'?mode=ro');
        if (!$source->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = '_folio_build'")->fetchColumn()) {
            throw new \RuntimeException('Rebuild this folio at the producer: the archive has no shared _folio_build identity.');
        }
        $identity = $source->query('SELECT build_id, schema_version FROM _folio_build')->fetchAll(\PDO::FETCH_ASSOC);
        if (1 !== count($identity) || DuckDbFolioExporter::SCHEMA_VERSION !== (int) $identity[0]['schema_version']) {
            throw new \RuntimeException('The folio needs a supported shared build identity.');
        }
        $contracts = [];
        foreach ($source->query('SELECT DISTINCT dto_type FROM item ORDER BY dto_type')->fetchAll(\PDO::FETCH_COLUMN) as $type) {
            $class = $this->types->classForType($type) ?? throw new \RuntimeException('No DTO contract for '.$type);
            $contracts[$type] = $this->schema->structure($class);
        }

        return [$identity[0]['build_id'], hash('sha256', json_encode([
            'export' => DuckDbFolioExporter::SCHEMA_VERSION,
            'projection' => DuckDbFolioSearchProjection::VERSION,
            'fts' => $fts ? DuckDbFtsIndexer::VERSION : null,
            'contracts' => $contracts,
        ], JSON_THROW_ON_ERROR))];
    }
}
