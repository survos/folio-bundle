<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Cache\CacheItemPoolInterface;
use Survos\FolioBundle\Entity\{Claim,Core,Doc,Folio,Link,LinkType,Page,Row,SchemaProperty,SchemaTable,Str,StrTranslation,Term,TermSet};

final class FolioSchemaManager
{
    private const array ENTITIES = [Folio::class, Core::class, Row::class, Page::class, Claim::class, SchemaTable::class, SchemaProperty::class, Doc::class, TermSet::class, Term::class, LinkType::class, Link::class, Str::class, StrTranslation::class];

    /** Memoized per process — the entity schema is constant for a deploy. */
    private ?int $expectedVersion = null;

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
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
     * generating the CREATE DDL for all 14 entities and throwing the SQL away — ~10ms warm, ~80ms
     * in a cold process. The property memo above is per-process, which under PHP-FPM means once per
     * REQUEST, so every folio page paid it to recompute a value that is constant for the whole
     * deploy. Hence the second-level cache, keyed on the newest mtime of the entity sources: edit an
     * entity and the key changes, so it recomputes on its own with no bump-me-by-hand step.
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
        $sql = (new SchemaTool($em))->getCreateSchemaSql($metas);
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

    /** True when the folio's stored schema version matches the deployed entity schema. */
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

        $conn = $em->getConnection();
        $platform = $conn->getDatabasePlatform();
        $mf = $em->getMetadataFactory();
        $schemaTool = new SchemaTool($em);

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
        $conn->executeStatement(sprintf('PRAGMA user_version = %d', $this->expectedVersion($em)));
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
