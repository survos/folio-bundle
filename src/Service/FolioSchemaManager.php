<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Survos\FolioBundle\Entity\{Claim,Core,Doc,Folio,Link,LinkType,Row,SchemaProperty,SchemaTable,Term,TermSet};

final class FolioSchemaManager
{
    private const array ENTITIES = [Folio::class, Core::class, Row::class, Claim::class, SchemaTable::class, SchemaProperty::class, Doc::class, TermSet::class, Term::class, LinkType::class, Link::class];

    public function update(EntityManagerInterface $em): void
    {
        $mf = $em->getMetadataFactory();
        $metas = array_map(static fn (string $class) => $mf->getMetadataFor($class), self::ENTITIES);
        (new SchemaTool($em))->updateSchema($metas);
        if (!$em->getConnection()->createSchemaManager()->tablesExist(['folio'])) {
            throw new \RuntimeException('Folio schema update failed; the folio table was not created.');
        }
    }
}
