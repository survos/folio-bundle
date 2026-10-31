<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Dto\DuckDbS3Configuration;

/** Read local snapshots or attach remote snapshots directly; no sync, restore or local file copy. */
final class DuckDbFolioReader
{
    public function open(string $path, ?DuckDbS3Configuration $s3 = null): \PDO
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('DuckDB reads require ext-pdo_duckdb (DuckDB 2.0).');
        }
        $url = parse_url($path);
        $remote = false !== $url && isset($url['scheme']);
        if ($remote) {
            if (!in_array($url['scheme'], ['s3', 'https'], true) || !isset($url['host'], $url['path'])
                || !DuckDbFolioFile::accepts($url['path']) || isset($url['user']) || isset($url['pass'])
                || isset($url['fragment']) || ('s3' === $url['scheme'] && isset($url['query']))) {
                throw new \InvalidArgumentException('Use an s3:// or https:// URI pointing to a .dfolio snapshot.');
            }
            if ('s3' !== $url['scheme'] && null !== $s3) {
                throw new \InvalidArgumentException('S3 credentials require an s3:// snapshot URI.');
            }
            $db = new \PDO('duckdb::memory:');
        } else {
            if (!is_file($path) || null !== $s3) {
                throw new \InvalidArgumentException('Local DuckDB snapshots must exist and do not use S3 credentials.');
            }
            $db = new \PDO('duckdb:'.$path, options: [\PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only']]);
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
            $s3?->configure($db, $path);
            // ATTACH is a SQL utility statement, so quote the URI rather than shelling out.
            $db->exec('ATTACH '.$db->quote($path).' AS folio (READ_ONLY)');
            $db->exec('USE folio');
        }

        return $db;
    }
}
