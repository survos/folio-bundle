<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Survos\FolioBundle\Exception\FolioMigrationInProgressException;
use Survos\FolioBundle\Entity\{Claim,Core,Doc,Folio,Link,LinkType,Page,Row,SchemaProperty,SchemaTable,Str,StrTranslation,Term,TermSet};

final class FolioSchemaManager
{
    private const array ENTITIES = [Folio::class, Core::class, Row::class, Page::class, Claim::class, SchemaTable::class, SchemaProperty::class, Doc::class, TermSet::class, Term::class, LinkType::class, Link::class, Str::class, StrTranslation::class];

    /** Memoized per process — the entity schema is constant for a deploy. */
    private ?int $expectedVersion = null;

    /** @see schemaTool() — memoized because building one creates a metadata factory. */
    private ?SchemaTool $schemaTool = null;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * A SchemaTool bound to a THROWAWAY EntityManager that shares this one's connection and
     * configuration but has an EMPTY EventManager -- so nothing it does can dispatch Doctrine's
     * postGenerateSchema/postGenerateSchemaTable events.
     *
     * This is not a micro-optimization, it is a correctness fix. Symfony registers its messenger /
     * lock / cache / session schema listeners against EVERY entity manager, and each one reacts to
     * postGenerateSchema by calling getIsSameDatabaseChecker(), which CREATEs a scratch table named
     * `schema_subscriber_check_` in the event manager's connection, INSERTs a row, then DROPs it
     * again. For the folio EM that connection is a folio file -- so every stale-folio open wrote
     * ~170 CREATE/INSERT/DELETE/DROP cycles into a read-mostly SQLite database that Symfony has no
     * business writing to at all (measured on wikibase/enslaved, 2026-08-25: 1044 of the request's
     * 1625 queries, against a 2.4 GB file).
     *
     * Worse, it is not concurrency-safe: two overlapping requests on the same stale folio interleave
     * their create/drop cycles, and one drops the scratch table between the other's CREATE and its
     * INSERT. That surfaced as "no such table: schema_subscriber_check_", which update()'s caller
     * then reported as "schema is out of date, re-import required" -- a completely wrong diagnosis
     * of a transient race, on a folio that was fine.
     *
     * SchemaTool only ever needs the connection (for the platform) and the configuration (for
     * metadata + naming strategy); it has no legitimate use for app event listeners here. Giving it
     * an EM with no listeners removes the entire class of problem rather than working around it.
     */
    private function schemaTool(EntityManagerInterface $em): SchemaTool
    {
        return $this->schemaTool ??= new SchemaTool(
            new EntityManager($em->getConnection(), $em->getConfiguration(), new EventManager()),
        );
    }

    /**
     * Fingerprint of the current entity schema: a checksum of the CREATE SQL the metadata produces.
     * Stored per-folio in SQLite's PRAGMA user_version (the native "schema version" slot), so an open
     * can tell at a glance whether a folio matches the deployed schema — no extra column (which a
     * pre-existing folio wouldn't have to read), no env var to set on deploy (which would drift).
     * Add/rename a column and this value changes; masked to 31 bits so it's always a positive int
     * (user_version is a signed 32-bit int).
     *
     * Reading the stored version is one PRAGMA (~0.01ms), but PRODUCING this expected value means
     * generating the CREATE DDL for all 14 entities and throwing the SQL away. The property memo
     * above is per-process, which under PHP-FPM means once per REQUEST, so every folio page paid it
     * to recompute a value that is constant for the whole deploy. Hence the second-level cache,
     * keyed on the newest mtime of the entity sources: edit an entity and the key changes, so it
     * recomputes on its own with no bump-me-by-hand step.
     *
     * Note the pool this lands in is cache.system (that is what CacheItemPoolInterface autowires
     * to), so it is wiped by every container rebuild, not just by a deploy — the first request after
     * any cache:clear pays full price. That was survivable only because schemaTool() stopped the DDL
     * generation from dispatching Doctrine's schema events; before that, this one call also fanned
     * out into a dozen CREATE/DROP round-trips against the folio file.
     *
     * The gap that leaves: a change to Doctrine *config* rather than the entity files (naming
     * strategy, a custom type) can alter the generated DDL without moving any mtime, so it needs a
     * cache:clear to be picked up — which a deploy does anyway.
     */
    public function expectedVersion(EntityManagerInterface $em): int
    {
        if ($this->expectedVersion !== null) {
            return $this->expectedVersion;
        }

        $fingerprint = $this->entitySourcesFingerprint();
        // No readable entity sources (unexpected) — compute every time rather than cache under a
        // key that can never change.
        $item = $fingerprint === null ? null : $this->cache->getItem('survos_folio.schema_version.' . $fingerprint);
        if ($item?->isHit()) {
            return $this->expectedVersion = (int) $item->get();
        }

        $mf = $em->getMetadataFactory();
        $metas = array_map(static fn (string $class) => $mf->getMetadataFor($class), self::ENTITIES);
        $sql = $this->schemaTool($em)->getCreateSchemaSql($metas);
        sort($sql);
        $version = crc32(implode("\n", $sql)) & 0x7fffffff;

        if ($item !== null) {
            // A read-only build dir just means save() fails and we recompute next process — the
            // value stays correct either way.
            $this->cache->save($item->set($version));
        }

        return $this->expectedVersion = $version;
    }

    /** Newest mtime across the entity sources, or null when they can't be read. */
    private function entitySourcesFingerprint(): ?string
    {
        $files = glob(dirname(__DIR__) . '/Entity/*.php') ?: [];
        if ($files === []) {
            return null;
        }

        $newest = 0;
        foreach ($files as $file) {
            $newest = max($newest, (int) filemtime($file));
        }

        return $newest === 0 ? null : (string) $newest;
    }

    /**
     * True when the folio's stored schema version matches the deployed entity schema.
     *
     * Impure by design: it reads PRAGMA user_version from the file every call, so a second call can
     * legitimately disagree with the first — that is exactly what update() relies on when it
     * re-checks after waiting on the migration lock and finds another process already did the work.
     *
     * @phpstan-impure
     */
    public function isCurrent(EntityManagerInterface $em): bool
    {
        return (int) $em->getConnection()->fetchOne('PRAGMA user_version') === $this->expectedVersion($em);
    }

    /**
     * Bring a folio's schema up to date by ADDING any columns the entities define but the file lacks
     * — the "new column without a full rebuild" path — then stamp PRAGMA user_version so future opens
     * skip straight past. No-op (one PRAGMA read) when the folio is already current.
     *
     * We deliberately do NOT use SchemaTool::updateSchema(): it introspects the whole folio, and a
     * built folio carries SQLite *expression* indexes (idx_item_json_*, ON item(json_extract(...)))
     * plus FTS5 virtual/shadow tables (item_fts*) that Doctrine's SQLite introspector can't read —
     * it builds an Index with a null column and throws. Instead we take the TARGET columns from the
     * metadata (no introspection) and diff them against PRAGMA table_info (raw SQL, index-agnostic),
     * issuing one ALTER TABLE … ADD COLUMN per missing column. Brand-new entity tables (rare on an
     * existing folio) are created outright. If a change can't be applied this way (e.g. a dropped or
     * retyped column), the ALTER throws — the folio is structurally incompatible and must be re-imported.
     */
    public function update(EntityManagerInterface $em): void
    {
        // BEFORE the isCurrent() early-return, deliberately. sort_key is a SQLite GENERATED
        // column, not a Doctrine-mapped one, so the metadata-driven pass below can never create
        // it -- and PRAGMA user_version knows nothing about it either. A folio can therefore be
        // "schema-current" by version and still lack the column, which is exactly the state
        // folio:build leaves behind: it is only ever added by FolioFtsIndexer, so a folio that
        // was built but not FTS-rebuilt has no sort_key at all.
        //
        // That combination is nastier than it sounds. Nothing fails at build time; the folio
        // opens, and gallery and map render normally. It only blows up when something sorts by
        // year -- FolioController's 'year' => 'd.sort_key' -- so it presents as one broken folio
        // rather than a missing build step, and it has cost real debugging time more than once.
        // Ensuring it here means every folio self-heals on first open, with no rebuild.
        $this->ensureGeneratedColumns($em);

        if ($this->isCurrent($em)) {
            return;
        }

        // Serialize migration per folio FILE, not per process. Two overlapping web requests on the
        // same stale folio used to each run the whole migration concurrently; the ALTERs tolerate
        // that (see the "duplicate column" catch below) but nothing else here was designed for it,
        // and it is pure duplicated work. The loser waits ~25 ms, re-checks, and finds the folio
        // already current.
        $path = (string) ($em->getConnection()->getParams()['path'] ?? '');
        $handle = $path === '' ? null : $this->lockForMigration($path);

        try {
            // Re-check under the lock: whoever held it may have just done our work for us.
            if ($handle !== null && $this->isCurrent($em)) {
                return;
            }
            $this->applySchema($em);
        } finally {
            if ($handle !== null) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /**
     * Exclusive advisory lock on a sidecar `<folio>.lock`, polled rather than blocking so a stuck
     * holder can never hang a web request indefinitely. A sidecar file rather than the folio itself
     * because SQLite already uses fcntl byte-range locks on that file — mixing the two mechanisms
     * on one inode is legal on Linux but needlessly hard to reason about.
     *
     * @throws FolioMigrationInProgressException when the holder outlasts the wait window
     */
    private function lockForMigration(string $path, int $waitMs = 2000): mixed
    {
        $handle = @fopen($path . '.lock', 'c');
        if ($handle === false) {
            // An unwritable folio dir is a deployment problem, not a reason to refuse to open a
            // folio that may not even need migrating. Proceed unlocked, as before this existed.
            $this->logger?->warning('Folio migration lock unavailable; proceeding unserialized', ['path' => $path]);

            return null;
        }

        $deadline = microtime(true) + $waitMs / 1000;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        fclose($handle);

        throw new FolioMigrationInProgressException($path, $waitMs);
    }

    /** The actual DDL pass. Always called with the migration lock held (when one could be taken). */
    private function applySchema(EntityManagerInterface $em): void
    {
        $conn = $em->getConnection();
        $platform = $conn->getDatabasePlatform();
        $mf = $em->getMetadataFactory();
        $schemaTool = $this->schemaTool($em);

        // A migration that reaches here is the ONE thing on the folio read path that both writes
        // and can be slow, so it is the one thing worth timing. Without this an in-place migration
        // is invisible in the logs -- it either succeeds (and looks like a slow page) or fails (and
        // gets reported as "re-import required"), with nothing to say which migrations are cheap
        // enough to keep doing inline on a web request and which belong on a queue.
        $startedAt = microtime(true);
        $from = (int) $conn->fetchOne('PRAGMA user_version');
        $to = $this->expectedVersion($em);
        $addedColumns = [];
        $createdTables = [];

        foreach (self::ENTITIES as $class) {
            $meta = $mf->getMetadataFor($class);
            $table = $meta->getTableName();
            $quotedTable = $platform->quoteSingleIdentifier($table);

            // Existing columns via PRAGMA — never touches indexes, so it can't trip over the
            // expression indexes / FTS5 tables that break Doctrine introspection.
            $info = $conn->fetchAllAssociative(sprintf('PRAGMA table_info(%s)', $quotedTable));
            $existing = array_map(static fn (array $r): string => (string) $r['name'], $info);

            if ($existing === []) {
                // Brand-new table: create it from metadata (also introspection-free).
                foreach ($schemaTool->getCreateSchemaSql([$meta]) as $sql) {
                    $conn->executeStatement($sql);
                }
                $createdTables[] = $table;
                continue;
            }

            // Target columns come from the metadata only — no DB introspection.
            $targetTable = $schemaTool->getSchemaFromMetadata([$meta])->getTable($table);
            foreach ($targetTable->getColumns() as $column) {
                if (in_array($column->getObjectName()->toString(), $existing, true)) {
                    continue;
                }
                try {
                    $columnOptions = $column->toArray();
                    unset($columnOptions['comment']);

                    $conn->executeStatement(sprintf(
                        'ALTER TABLE %s ADD COLUMN %s',
                        $quotedTable,
                        $platform->getColumnDeclarationSQL($column->getObjectName()->toSQL($platform), $columnOptions),
                    ));
                    $addedColumns[] = $table . '.' . $column->getObjectName()->toString();
                } catch (\Throwable $e) {
                    // Tolerate a concurrent open that already added it (auto-migrate on open can race).
                    if (!str_contains($e->getMessage(), 'duplicate column')) {
                        throw $e;
                    }
                }
            }
        }

        if (!$conn->createSchemaManager()->tablesExist(['folio'])) {
            throw new \RuntimeException('Folio schema update failed; the folio table was not created.');
        }

        // Stamp the new schema version so subsequent opens are a cheap no-op.
        $conn->executeStatement(sprintf('PRAGMA user_version = %d', $to));

        $this->logger?->info('Folio schema migrated in place', [
            'path' => $conn->getParams()['path'] ?? null,
            'from' => $from,
            'to' => $to,
            'ms' => round((microtime(true) - $startedAt) * 1000, 1),
            'addedColumns' => $addedColumns,
            'createdTables' => $createdTables,
        ]);
    }

    /**
     * Columns that exist in SQL but not in Doctrine's metadata, so update()'s metadata diff cannot
     * see them. Cheap enough to run on every open: one PRAGMA when the column is already there.
     */
    private function ensureGeneratedColumns(EntityManagerInterface $em): void
    {
        $native = $em->getConnection()->getNativeConnection();
        if (!$native instanceof \PDO) {
            return;
        }

        // A folio that has no `item` table yet (a fresh bootstrap) has nothing to add the column
        // to; update() creates the table below and the next open ensures the column.
        try {
            FolioFtsIndexer::ensurePrimarySortColumn($native);
        } catch (\Throwable) {
            // Never let this break opening a folio: sorting by year degrades, everything else
            // works, and the alternative is a folio that cannot be read at all.
        }
    }
}
