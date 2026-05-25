<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

final readonly class FolioArchiveService
{
    public function __construct(
        private FolioService $folios,
        private FolioFtsIndexer $ftsIndexer,
        private FolioArchivePreparer $preparer,
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
        $pdo->exec('DROP TABLE IF EXISTS item_vocab');
        $pdo->exec('DROP TABLE IF EXISTS item_fts');
        $pdo->exec('DROP INDEX IF EXISTS idx_item_core_dto_type');
        $pdo->exec('DROP INDEX IF EXISTS idx_item_dto_type');
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
        $indexed = $this->ftsIndexer->rebuild($target);

        return [
            'archive' => $archivePath,
            'target' => $target,
            'targetBytes' => filesize($target) ?: 0,
            'indexedRows' => $indexed['rows'],
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
