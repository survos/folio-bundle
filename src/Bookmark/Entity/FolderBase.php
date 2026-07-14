<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Bookmark\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Enum\Widget;
use Survos\FolioBundle\Bookmark\Enum\FolderVisibility;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Ulid;

/**
 * A user-owned folder for organizing bookmarked folio rows. Flat (no nesting).
 *
 * Deliberately holds NO relations (not $user, not $bookmarks) and no
 * $bookmarkCount — those depend on the $bookmarks relation, which only the
 * host app's concrete Folder class can declare. See docs/bookmarks.md.
 */
#[ORM\MappedSuperclass]
abstract class FolderBase
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid', unique: true)]
    #[Groups(['folder:read'])]
    public Ulid $id;

    #[ORM\Column]
    #[Groups(['folder:read'])]
    public \DateTimeImmutable $created;

    #[ORM\Column(length: 120)]
    #[Field(searchable: true, sortable: true, order: 10)]
    #[Groups(['folder:read', 'folder:write'])]
    public string $name;

    /** Assigned once at creation; stays stable on rename. */
    #[ORM\Column(length: 140)]
    #[Field(sortable: true, order: 11, visible: false)]
    #[Groups(['folder:read'])]
    public string $slug;

    #[ORM\Column(length: 16, enumType: FolderVisibility::class)]
    #[Field(filterable: true, facet: true, widget: Widget::Select, order: 12, width: '8rem')]
    #[Groups(['folder:read', 'folder:write'])]
    public FolderVisibility $visibility;

    /** Not serialized — read by Get operation security expressions only. */
    public bool $isPrivate {
        get => $this->visibility === FolderVisibility::Private;
    }
}
