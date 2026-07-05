<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\BabelBundle\Entity\Base\StrTranslationBase;
use Survos\BabelBundle\Runtime\BabelSchema;
use Survos\FolioBundle\Repository\StrTranslationRepository;

/** @see Str for why this is folio-bundle's own copy rather than babel's entity. */
#[ORM\Entity(repositoryClass: StrTranslationRepository::class)]
#[ORM\Table(name: BabelSchema::STR_TR_TABLE)]
#[ORM\UniqueConstraint(name: 'str_tr_uq', columns: ['str_code', 'target_locale', 'engine'])]
class StrTranslation extends StrTranslationBase
{
}
