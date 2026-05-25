<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\FolioBundle\DBAL\FolioConnectionWrapper;
use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Model\FolioContext;

final class FolioService
{
    public ?string $currentFolioCode = null;

    public function __construct(
        private readonly EntityManagerInterface $folioEntityManager,
        private readonly FolioSchemaManager $schemaManager,
        private readonly string $dbDir,
        private readonly string $extension = 'folio.sqlite',
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function path(string $folioCode, bool $createDirectory = false): string
    {
        $path = sprintf('%s/%s.%s', rtrim($this->dbDir, '/'), $folioCode, ltrim($this->extension, '.'));
        if ($createDirectory) {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Unable to create folio directory "%s".', $dir));
            }
        }
        return $path;
    }

    /**
     * Wipe a folio and restore it from the bootstrap template.
     * The bootstrap is created on first use if it does not exist.
     * This is the "restore from backup" path — call before ingest.
     */
    public function reset(string $folioCode): void
    {
        $bootstrap = $this->bootstrapPath();
        if (!is_file($bootstrap)) {
            $this->buildBootstrap();
        }

        $target = $this->path($folioCode, createDirectory: true);
        if (!copy($bootstrap, $target)) {
            throw new \RuntimeException(sprintf('Failed to copy bootstrap to "%s".', $target));
        }

        $this->invalidateConnection();
        $this->logger?->info('Folio reset from bootstrap', ['folio' => $folioCode, 'path' => $target]);
    }

    public function switch(string $folioCode): EntityManagerInterface
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

        $target = $this->path($folioCode);
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
    public function context(string $folioCode, bool $ensureSchema = false): FolioContext
    {
        $em = $this->switch($folioCode);
        if ($ensureSchema) {
            $this->schemaManager->update($em);
        }

        if ($em->find(Folio::class, $folioCode) === null) {
            $folio = new Folio($folioCode);
            $em->persist($folio);
            $em->flush();
        }

        return new FolioContext($folioCode, $this->path($folioCode), $em);
    }

    private function bootstrapPath(): string
    {
        // _bootstrap lives at the root of the folio db dir.
        return rtrim($this->dbDir, '/') . '/_bootstrap.' . ltrim($this->extension, '.');
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
