<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Survos\FolioBundle\Entity\{Claim,Core,Doc,Folio,Link,LinkType,Page,Row,SchemaProperty,SchemaTable,Term,TermSet};

final class FolioSchemaManager
{
    private const array ENTITIES = [Folio::class, Core::class, Row::class, Page::class, Claim::class, SchemaTable::class, SchemaProperty::class, Doc::class, TermSet::class, Term::class, LinkType::class, Link::class];

    /** Memoized per process — the entity schema is constant for a deploy. */
    private ?int $expectedVersion = null;

    /**
     * Fingerprint of the current entity schema: a checksum of the CREATE SQL the metadata produces.
     * Stored per-folio in SQLite's PRAGMA user_version (the native "schema version" slot), so an open
     * can tell at a glance whether a folio matches the deployed schema — no extra column (which a
     * pre-existing folio wouldn't have to read), no env var to set on deploy (which would drift).
     * Add/rename a column and this value changes; masked to 31 bits so it's always a positive int
     * (user_version is a signed 32-bit int).
     */
    public function expectedVersion(EntityManagerInterface $em): int
    {
        if ($this->expectedVersion !== null) {
            return $this->expectedVersion;
        }
        $mf = $em->getMetadataFactory();
        $metas = array_map(static fn (string $class) => $mf->getMetadataFor($class), self::ENTITIES);
        $sql = (new SchemaTool($em))->getCreateSchemaSql($metas);
        sort($sql);

        return $this->expectedVersion = crc32(implode("\n", $sql)) & 0x7fffffff;
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
                if (in_array($column->getName(), $existing, true)) {
                    continue;
                }
                try {
                    $conn->executeStatement(sprintf(
                        'ALTER TABLE %s ADD COLUMN %s',
                        $quotedTable,
                        $platform->getColumnDeclarationSQL($column->getQuotedName($platform), $column->toArray()),
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
}
