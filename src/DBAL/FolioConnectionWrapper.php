<?php

declare(strict_types=1);

namespace Survos\FolioBundle\DBAL;

use Doctrine\DBAL\{Configuration,Connection,Driver};

final class FolioConnectionWrapper extends Connection
{
    public string $currentPath;
    private bool $readOnly = false;
    /** This connection switched the file to WAL for a write; put it back to DELETE on close. */
    private bool $walApplied = false;

    public function __construct(array $params, Driver $driver, ?Configuration $config = null)
    {
        /** @phpstan-ignore method.internal */
        parent::__construct($params, $driver, $config);
        $this->currentPath = (string) ($params['path'] ?? $params['dbname'] ?? '');
    }

    public function selectDatabase(string $path, bool $readOnly = false): void
    {
        if ($this->currentPath === $path && $this->readOnly === $readOnly) {
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
        $this->currentPath = $path;
        $this->readOnly = $readOnly;
        $params['readOnly'] = $readOnly;
        $params['path'] = $readOnly ? 'file:'.str_replace('%2F', '/', rawurlencode($path)).'?'.self::readOnlyQuery($path) : $path;
        /** @phpstan-ignore method.internal */
        parent::__construct($params, $this->getDriver(), $this->_config);

        $this->applyPragmas();
    }

    /**
     * mode=ro, plus immutable=1 when a plain read-only open is bound to fail.
     *
     * A WAL-mode file needs its -shm beside it; in a directory the reader cannot write, SQLite
     * cannot create one and every open dies with "unable to open database file" (error 14). That
     * took inkstory.org down on 2026-09-24, when a zm dataset:scan left 3,941 folios in WAL mode
     * and Ink reads /platform through a read-only mount. immutable=1 skips WAL and locking, so it
     * is used only in exactly that case: everywhere else a reader keeps SQLite's shared lock
     * against a writer rewriting the file in place.
     */
    public static function readOnlyQuery(string $path): string
    {
        $header = @file_get_contents($path, false, null, 18, 1);
        $wal = $header === "\x02";

        return $wal && !is_writable(\dirname($path)) ? 'mode=ro&immutable=1' : 'mode=ro';
    }

    /**
     * WAL is for writing, so it starts with the first write transaction, not with the connection.
     *
     * Asserting WAL on every connect meant every open changed the file: a zm dataset:scan that only
     * read 3,942 folios left all of them in WAL mode (2026-09-23), and after that was fixed with a
     * restore-on-close, zm's web workers kept flipping ~90 more a day, because two workers holding
     * the same file each saw it already in WAL and neither put it back. Reads never get here, so
     * browsing a folio now leaves its header alone. journal_mode cannot change inside a
     * transaction, so this runs only at the outermost begin.
     */
    public function beginTransaction(): void
    {
        if (!$this->readOnly && !$this->walApplied && $this->getTransactionNestingLevel() === 0) {
            $this->executeStatement('PRAGMA journal_mode = WAL');
            $this->walApplied = true;
        }
        parent::beginTransaction();
    }

    /**
     * A folio at rest is in DELETE mode (see FolioService::finalize()), so a connection that
     * switched it to WAL switches it back on the way out, whether or not the caller reached
     * finalize(). Best effort: leaving WAL needs an exclusive lock, so while another writer still
     * holds the file this fails and that writer, which also applied WAL, restores it when it
     * closes. A killed process cannot run this; the reader-side immutable fallback in
     * {@see readOnlyQuery()} covers that.
     */
    public function close(): void
    {
        if ($this->walApplied && $this->isConnected() && !$this->isTransactionActive()) {
            try {
                // Don't sit out the 30 s busy_timeout for a lock another writer is holding.
                $this->executeStatement('PRAGMA busy_timeout = 0');
                $this->executeStatement('PRAGMA journal_mode = DELETE');
            } catch (\Throwable) {
                // another connection holds the file, or the mount is read-only
            }
        }
        $this->walApplied = false;
        parent::close();
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
            // shutdown order is not ours to choose
        }
    }

    /**
     * Per-connection settings; none of these is written into the file. busy_timeout must be
     * re-applied on every connection, or concurrent writers (background workers, ingest + browse)
     * die immediately with SQLITE_BUSY instead of waiting briefly. journal_mode is deliberately
     * not here: it IS persisted in the file, so {@see beginTransaction()} sets it only for writes.
     */
    private function applyPragmas(): void
    {
        if ($this->readOnly) {
            $this->executeStatement('PRAGMA query_only = ON');
            $this->executeStatement('PRAGMA busy_timeout = 30000');
            return;
        }
        $this->executeStatement('PRAGMA busy_timeout = 30000');
        $this->executeStatement('PRAGMA synchronous = NORMAL');
        $this->executeStatement('PRAGMA foreign_keys = ON');
    }
}
