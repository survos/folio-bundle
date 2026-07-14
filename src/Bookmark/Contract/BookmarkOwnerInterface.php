<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Bookmark\Contract;

/**
 * Optional marker a host app's User can implement so BookmarkManager's
 * method signatures read as something more specific than `object $user`.
 * Not required — BookmarkManager works with any object the host's concrete
 * Bookmark/Folder constructors accept as $user. See docs/bookmarks.md.
 */
interface BookmarkOwnerInterface
{
    public function getId(): int|string|null;
}
