<?php

declare(strict_types=1);

namespace Survos\FolioBundle\DBAL;

use Doctrine\DBAL\{Configuration,Connection,Driver};

final class FolioConnectionWrapper extends Connection
{
    public string $currentPath;

    public function __construct(array $params, Driver $driver, ?Configuration $config = null)
    {
        /** @phpstan-ignore method.internal */
        parent::__construct($params, $driver, $config);
        $this->currentPath = (string) ($params['path'] ?? $params['dbname'] ?? '');
    }

    public function selectDatabase(string $path): void
    {
        if ($this->currentPath === $path) {
            return;
        }
        if ($this->isConnected()) {
            $this->close();
        }
        /** @var array<string, mixed> $params */
        /** @phpstan-ignore method.internal */
        $params = $this->getParams();
        unset($params['url'], $params['dbname']);
        $params['driver'] ??= 'pdo_sqlite';
        $params['path'] = $this->currentPath = $path;
        /** @phpstan-ignore method.internal */
        parent::__construct($params, $this->getDriver(), $this->_config);

        $this->applyPragmas();
    }

    /**
     * busy_timeout must be re-applied on every connection — it is per-connection,
     * not persisted in the file. Without it, concurrent writers (background workers,
     * ingest + browse) die immediately with SQLITE_BUSY instead of waiting briefly.
     * journal_mode = WAL is persisted in the file header but cheap to re-assert.
     */
    private function applyPragmas(): void
    {
        $this->executeStatement('PRAGMA journal_mode = WAL');
        $this->executeStatement('PRAGMA busy_timeout = 30000');
        $this->executeStatement('PRAGMA synchronous = NORMAL');
        $this->executeStatement('PRAGMA foreign_keys = ON');
    }
}
