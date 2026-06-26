<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Survos\FolioBundle\Entity\{Claim,Core,Doc,Folio,Link,LinkType,Page,Row,SchemaProperty,SchemaTable,Term,TermSet};

final class FolioSchemaManager
{
    private const array ENTITIES = [Folio::class, Core::class, Row::class, Page::class, Claim::class, SchemaTable::class, SchemaProperty::class, Doc::class, TermSet::class, Term::class, LinkType::class, Link::class];

    /**
     * Bring an existing folio's schema up to date by ADDING any columns the entities define but the
     * file lacks — the "new column without a full rebuild" path.
     *
     * We deliberately do NOT use SchemaTool::updateSchema(): it introspects the whole folio, and a
     * built folio carries SQLite *expression* indexes (idx_item_json_*, ON item(json_extract(...)))
     * plus FTS5 virtual/shadow tables (item_fts*) that Doctrine's SQLite introspector can't read —
     * it builds an Index with a null column and throws. Instead we take the TARGET columns from the
     * metadata (no introspection) and diff them against PRAGMA table_info (raw SQL, index-agnostic),
     * issuing one ALTER TABLE … ADD COLUMN per missing column. Brand-new entity tables (rare on an
     * existing folio) are created outright.
     */
    public function update(EntityManagerInterface $em): void
    {
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
                $conn->executeStatement(sprintf(
                    'ALTER TABLE %s ADD COLUMN %s',
                    $quotedTable,
                    $platform->getColumnDeclarationSQL($column->getQuotedName($platform), $column->toArray()),
                ));
            }
        }

        if (!$conn->createSchemaManager()->tablesExist(['folio'])) {
            throw new \RuntimeException('Folio schema update failed; the folio table was not created.');
        }
    }
}
