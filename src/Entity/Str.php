<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Entity\Base\StrBase;
use Survos\BabelBundle\Runtime\BabelSchema;
use Survos\FolioBundle\Repository\StrRepository;

/**
 * Folio's own copy of babel's Str entity, mapped into the folio entity manager
 * (not babel's own Survos\BabelBundle\Entity\Str, which would collide -- babel's
 * own TermSet entity already maps to the same 'term_set' table name folio-bundle's
 * own TermSet uses). Same 'str' table shape either way; BabelPostLoadHydrator
 * queries by raw table name, so it doesn't care which entity class owns the mapping.
 */
#[ORM\Entity(repositoryClass: StrRepository::class)]
#[ORM\Table(name: BabelSchema::STR_TABLE)]
#[ORM\UniqueConstraint(name: 'str_code_uq', columns: ['code'])]
class Str extends StrBase
{
}
