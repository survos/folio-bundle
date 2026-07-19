<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Bookmark\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Enum\Widget;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Ulid;

/**
 * A user's bookmark on a single folio row. Folio content lives in a per-folio
 * SQLite database (survos/folio-bundle), a different DBAL connection/engine, so
 * this stores the row's identity fields as plain columns rather than a relation.
 *
 * Row identity mirrors Survos\FolioBundle\Entity\Row::id()/getRp():
 * provider + dataset (= folioCode) + coreCode + localId. dtoType is a display/
 * redirect hint, not identity.
 *
 * Deliberately holds NO relations (not $user, not $folder) — see docs/bookmarks.md
 * for why. The host app's concrete Bookmark class (App\Entity\Bookmark) declares
 * every association itself, since it alone knows its own User/Folder classes.
 */
#[ORM\MappedSuperclass]
abstract class BookmarkBase
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid', unique: true)]
    #[Groups(['bookmark:read'])]
    public Ulid $id;

    #[ORM\Column]
    #[Field(sortable: true, widget: Widget::Date, order: 90, format: 'datetime')]
    #[Groups(['bookmark:read'])]
    public \DateTimeImmutable $created;

    #[ORM\Column(length: 64)]
    #[Field(filterable: true, facet: true, order: 20)]
    #[Groups(['bookmark:read'])]
    public string $provider;

    #[ORM\Column(length: 128)]
    #[Field(filterable: true, facet: true, order: 21)]
    #[Groups(['bookmark:read'])]
    public string $dataset;

    #[ORM\Column(length: 64)]
    #[Field(filterable: true, order: 22)]
    #[Groups(['bookmark:read'])]
    public string $coreCode;

    #[ORM\Column(length: 180)]
    #[Groups(['bookmark:read'])]
    public string $localId;

    /** Display cache, snapshotted at bookmark time — not part of row identity. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Field(filterable: true, facet: true, order: 23)]
    #[Groups(['bookmark:read'])]
    public ?string $dtoType = null;

    /** Display cache, snapshotted at bookmark time — not part of row identity. */
    #[ORM\Column(length: 500, nullable: true)]
    #[Field(searchable: true, order: 30)]
    #[Groups(['bookmark:read'])]
    public ?string $label = null;

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
