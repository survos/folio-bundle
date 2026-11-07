<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Dto\DuckDbS3Configuration;

/** Read local snapshots or attach remote snapshots directly; no sync, restore or local file copy. */
final class DuckDbFolioReader
{
    /**
     * With survos/duckdb-bundle installed, opened dfolios are pooled (the ATTACH of a remote file is the expensive part)
     * and PDO calls show in the profiler. A pooled dfolio is shared: read it, do not change its session state. Without it, every call opens a fresh connection as before.
     */
    public function __construct(
        private readonly ?\Survos\DuckDbBundle\ConnectionPool $pool = null,
        private readonly ?\Survos\DuckDbBundle\ConnectionFactory $factory = null,
    ) {
    }

    /**
     * @param ?string $version for a remote file, anything that changes when the object is replaced (build id, ETag);
     *                         local files use their own mtime and size
     */
    public function open(string $path, ?DuckDbS3Configuration $s3 = null, ?string $version = null): \PDO
    {
        return $this->pooled($path, $s3, $version);
    }

    /**
     * Opens a fresh rendition. DuckDbRenditionReader pools it per file/build; all issue reads
     * use explicit filters so the pooled connection carries no issue-specific session state.
     */
    public function openRend(string $path, ?DuckDbS3Configuration $s3 = null): \PDO
    {
        return $this->openSnapshot($path, $s3, true);
    }

    private function pooled(string $path, ?DuckDbS3Configuration $s3, ?string $version): \PDO
    {
        if (null === $this->pool) {
            return $this->openSnapshot($path, $s3, false);
        }
        if (!str_contains($path, '://') && is_file($path)) {
            clearstatcache(true, $path);
            $version = filemtime($path).'.'.filesize($path);
        }

        return $this->pool->remember('dfolio|'.$path.'|'.($s3->host ?? ''), fn (): \PDO => $this->openSnapshot($path, $s3, false), $version, 'dfolio');
    }

    private function openSnapshot(string $path, ?DuckDbS3Configuration $s3, bool $rend): \PDO
    {
        $start = hrtime(true);
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('DuckDB reads require ext-pdo_duckdb (DuckDB 2.0).');
        }
        $url = parse_url($path);
        $remote = false !== $url && isset($url['scheme']);
        if ($remote) {
            if (!in_array($url['scheme'], ['s3', 'https'], true) || !isset($url['host'], $url['path'])
                || !($rend ? str_ends_with($url['path'], '.drend') : DuckDbFolioFile::accepts($url['path'])) || isset($url['user']) || isset($url['pass'])
                || isset($url['fragment']) || ('s3' === $url['scheme'] && isset($url['query']))) {
                throw new \InvalidArgumentException('Use an s3:// or https:// URI pointing to a .dfolio snapshot (or a .drend rendition).');
            }
            if ('s3' !== $url['scheme'] && null !== $s3) {
                throw new \InvalidArgumentException('S3 credentials require an s3:// snapshot URI.');
            }
            $db = $this->factory?->pdo('duckdb::memory:', $rend ? 'drend' : 'dfolio', location: $path, endpoint: $s3?->endpoint) ?? new \PDO('duckdb::memory:');
        } else {
            if (!is_file($path) || null !== $s3) {
                throw new \InvalidArgumentException('Local DuckDB snapshots must exist and do not use S3 credentials.');
            }
            $db = $this->factory?->pdo('duckdb:'.$path, $rend ? 'drend' : 'dfolio', [\PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only']]) ?? new \PDO('duckdb:'.$path, options: [\PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only']]);
        }
        $version = (string) $db->query('SELECT version()')->fetchColumn();
        if (!str_starts_with($version, 'v2.')) {
            throw new \RuntimeException('DuckDB 2.x is required; loaded '.$version);
        }
        if ($remote) {
            $db->exec('LOAD httpfs');
            // macOS libcurl proxy discovery is not fork-safe in PHP-FPM workers.
            if ('Darwin' === PHP_OS && 'fpm-fcgi' === PHP_SAPI) {
                $db->exec("SET httpfs_client_implementation = 'httplib'");
            }
            // DuckDB also probes the adjacent .wal; scope the secret to this immutable object's directory.
            $s3?->configure($db, substr($path, 0, (int) strrpos($path, '/') + 1));
            // ATTACH is a SQL utility statement, so quote the URI rather than shelling out.
            $db->exec('ATTACH '.$db->quote($path).' AS folio (READ_ONLY)');
            $db->exec('USE folio');
        }
        $this->factory?->finishOpen($db, (hrtime(true) - $start) / 1e6);

        return $db;
    }
}
