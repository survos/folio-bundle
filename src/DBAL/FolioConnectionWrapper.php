<?php

declare(strict_types=1);

namespace Survos\FolioBundle\DBAL;

use Doctrine\DBAL\{Configuration,Connection,Driver};

final class FolioConnectionWrapper extends Connection
{
    public string $currentPath;
    private bool $readOnly = false;
    /** The journal mode the file had before applyPragmas() forced WAL; put back on close. */
    private ?string $journalModeBefore = null;

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
     * busy_timeout must be re-applied on every connection — it is per-connection,
     * not persisted in the file. Without it, concurrent writers (background workers,
     * ingest + browse) die immediately with SQLITE_BUSY instead of waiting briefly.
     * journal_mode = WAL is persisted in the file header but cheap to re-assert.
     */
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
     * Put the journal mode back the way it was found. Opening a folio read-write must not leave
     * the file different from how it was, whether or not the caller reached finalize(): a scan
     * that only read 3,942 folios otherwise leaves every one of them in WAL mode.
     *
     * Best effort: it fails harmlessly while another connection holds the file (the last one out
     * restores it) and cannot run if the process is killed, which is what the reader-side
     * immutable fallback in {@see readOnlyQuery()} is for.
     */
    public function close(): void
    {
        if ($this->journalModeBefore !== null && $this->journalModeBefore !== 'wal' && $this->isConnected() && !$this->isTransactionActive()) {
            try {
                // Leaving WAL needs an exclusive lock; don't sit out the 30 s busy_timeout for it.
                $this->executeStatement('PRAGMA busy_timeout = 0');
                $this->executeStatement('PRAGMA journal_mode = '.$this->journalModeBefore);
            } catch (\Throwable) {
                // another connection holds the file, or the mount is read-only
            }
        }
        $this->journalModeBefore = null;
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

    private function applyPragmas(): void
    {
        if ($this->readOnly) {
            $this->executeStatement('PRAGMA query_only = ON');
            $this->executeStatement('PRAGMA busy_timeout = 30000');
            return;
        }
        $before = strtolower((string) $this->fetchOne('PRAGMA journal_mode'));
        $this->journalModeBefore = preg_match('/^(delete|truncate|persist)$/D', $before) ? $before : null;
        $this->executeStatement('PRAGMA journal_mode = WAL');
        $this->executeStatement('PRAGMA busy_timeout = 30000');
        $this->executeStatement('PRAGMA synchronous = NORMAL');
        $this->executeStatement('PRAGMA foreign_keys = ON');
    }
}
