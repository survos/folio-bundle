<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\FolioBundle\DBAL\FolioConnectionWrapper;
use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Model\FolioContext;

final class FolioService
{
    public ?string $currentFolioCode = null;

    /**
     * Content locale resolved from the current request's URL (e.g. "jarc.en" → "en"), set by
     * FolioRouteAttributeListener before routing. Callers that pass $locale explicitly always
     * win; this is only a fallback so controller actions don't each need to read the request
     * and thread a locale through — see survos-sites/scanseum#12/#18.
     */
    public ?string $requestContentLocale = null;

    public function __construct(
        private readonly EntityManagerInterface $folioEntityManager,
        private readonly FolioSchemaManager $schemaManager,
        private readonly DataPaths $dataPaths,
        private readonly string $extension = 'folio',
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** Pass $locale for a localized build, e.g. <code>.en.folio instead of <code>.folio. */
    public function path(string $folioCode, bool $createDirectory = false, ?string $locale = null): string
    {
        return $this->dataPaths->folioFile($folioCode, $this->localizedExtension($locale), $createDirectory);
    }

    public function rootPath(): string
    {
        return $this->dataPaths->folioRootDir;
    }

    /** Path to the index-free distributable archive, e.g. <root>/folio-archive/<provider>/<code>.folio.gz. */
    public function archivePath(string $folioCode, bool $createDirectory = false, ?string $locale = null): string
    {
        return $this->dataPaths->folioArchiveFile($folioCode, $this->localizedExtension($locale) . '.gz', $createDirectory);
    }

    private function localizedExtension(?string $locale): string
    {
        $locale ??= $this->requestContentLocale;

        return $locale !== null && $locale !== '' ? $locale . '.' . $this->extension : $this->extension;
    }

    /**
     * Wipe a folio and restore it from the bootstrap template.
     * The bootstrap is created on first use if it does not exist.
     * This is the "restore from backup" path — call before ingest.
     */
    public function reset(string $folioCode, ?string $locale = null): void
    {
        $bootstrap = $this->bootstrapPath();
        if (!is_file($bootstrap)) {
            $this->buildBootstrap();
        }

        $target = $this->path($folioCode, createDirectory: true, locale: $locale);

        // Close any open handle and drop a prior run's -wal/-shm/-journal BEFORE overwriting.
        // A stale WAL left by an aborted ingest would otherwise be replayed onto the fresh
        // bootstrap on next open and corrupt it. reset() means "start clean" — that WAL is discarded.
        $this->invalidateConnection();
        $this->removeSidecars($target);

        if (!copy($bootstrap, $target)) {
            throw new \RuntimeException(sprintf('Failed to copy bootstrap to "%s".', $target));
        }

        $this->logger?->info('Folio reset from bootstrap', ['folio' => $folioCode, 'path' => $target]);
    }

    /**
     * Abort the active folio connection: roll back any in-flight ingest transaction and close the
     * file cleanly so a killed build leaves no half-written WAL behind. Safe to call from a signal
     * handler. Uncommitted work is intentionally discarded — a reset + rebuild restores it.
     */
    public function closeActive(): void
    {
        $conn = $this->folioEntityManager->getConnection();
        if (!$conn instanceof FolioConnectionWrapper) {
            return;
        }

        try {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
        } catch (\Throwable) {
            // best effort — we are tearing down
        }
        try {
            if ($conn->isConnected()) {
                $conn->close(); // a clean close checkpoints + removes the -wal/-shm
            }
        } catch (\Throwable) {
            // best effort
        }
        $conn->currentPath = '';
    }

    /**
     * Has this folio been fully built? The inflate step creates the `item_fts` table last, so its
     * absence marks a folio whose build was aborted mid-ingest — even if the file exists and looks
     * recent. Read with a standalone PDO handle so we never disturb the shared EM connection.
     */
    public function isInflated(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        try {
            $pdo = new \PDO('sqlite:' . $path);

            return $pdo
                ->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='item_fts' LIMIT 1")
                ->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function removeSidecars(string $target): void
    {
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            $sidecar = $target . $suffix;
            if (is_file($sidecar)) {
                @unlink($sidecar);
            }
        }
    }

    public function switch(string $folioCode, ?string $locale = null): EntityManagerInterface
    {
        $em = $this->folioEntityManager;
        $conn = $em->getConnection();
        if (!$conn instanceof FolioConnectionWrapper) {
            throw new \RuntimeException(sprintf('Configure the folio DBAL connection with wrapper_class: %s', FolioConnectionWrapper::class));
        }

        if ($folioCode === '_bootstrap/_bootstrap') {
            $this->currentFolioCode = $folioCode;
            return $em;
        }

        $target = $this->path($folioCode, locale: $locale);
        if (!is_file($target)) {
            throw new \RuntimeException(sprintf('Folio file not found: %s. Run folio:migrate first.', $target));
        }
        if ($conn->currentPath !== $target) {
            try { $em->flush(); } catch (\Throwable) {}
            $em->clear();
            $conn->selectDatabase($target);
            $conn->executeQuery('SELECT 1')->fetchOne();
            $this->logger?->info('Folio database selected', ['folio' => $folioCode, 'path' => $target]);
        }
        $this->currentFolioCode = $folioCode;
        return $em;
    }

    /**
     * Open a folio, optionally updating its schema, and guarantee the Folio entity row exists.
     */
    public function context(string $folioCode, bool $ensureSchema = false, ?string $locale = null): FolioContext
    {
        $em = $this->switch($folioCode, $locale);

        // Self-healing schema: update() is a cheap PRAGMA-version check that no-ops when the folio
        // already matches the deployed entity schema, and ALTERs in new columns when it's stale — so
        // a folio migrates itself on first open after a schema change, no folio:migrate --all needed.
        // ($ensureSchema is kept for explicit callers; update() runs either way now.) A failure means
        // the change can't be applied incrementally (e.g. a dropped/retyped column) → re-import.
        try {
            $this->schemaManager->update($em);
        } catch (\Throwable $e) {
            $this->logger?->error('Folio schema auto-migrate failed — re-import required', [
                'folio' => $folioCode,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(sprintf(
                'Folio "%s" schema is out of date and could not be migrated in place (re-import required): %s',
                $folioCode,
                $e->getMessage(),
            ), 0, $e);
        }

        if ($em->find(Folio::class, $folioCode) === null) {
            $folio = new Folio($folioCode);
            $em->persist($folio);
            $em->flush();
        }

        return new FolioContext($folioCode, $this->path($folioCode, locale: $locale), $em);
    }

    private function bootstrapPath(): string
    {
        return $this->dataPaths->folioBootstrapFile($this->extension);
    }

    private function buildBootstrap(): void
    {
        $path = $this->bootstrapPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create bootstrap directory "%s".', $dir));
        }
        // Point the connection at the bootstrap file and apply schema.
        $conn = $this->folioEntityManager->getConnection();
        if (!$conn instanceof FolioConnectionWrapper) {
            throw new \RuntimeException('FolioConnectionWrapper required to build bootstrap.');
        }
        $conn->selectDatabase($path);
        $this->schemaManager->update($this->folioEntityManager);
        $this->invalidateConnection();
        $this->logger?->info('Bootstrap folio created', ['path' => $path]);
    }

    private function invalidateConnection(): void
    {
        $conn = $this->folioEntityManager->getConnection();
        if ($conn instanceof FolioConnectionWrapper) {
            if ($conn->isConnected()) {
                $conn->close();
            }
            $conn->currentPath = '';
        }
        $this->folioEntityManager->clear();
    }
}
