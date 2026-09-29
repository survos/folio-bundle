<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Bookmark\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/** Compatibility adapter: Folio routing stays outside the generic bookmark core. */
#[ORM\MappedSuperclass]
abstract class BookmarkBase extends \Survos\BookmarkBundle\Entity\BookmarkBase
{
    #[Groups(['bookmark:read'])]
    public string $folioCode {
        get => "$this->provider/$this->dataset";
    }

    /** Route params for survos_folio_row_show. */
    #[Groups(['bookmark:read'])]
    public array $rowRouteParams {
        get => [
            'folioCode' => $this->folioCode,
            'coreCode' => $this->coreCode,
            'dtoType' => $this->dtoType ?? 'item',
            'localId' => $this->localId,
        ];
    }
}
