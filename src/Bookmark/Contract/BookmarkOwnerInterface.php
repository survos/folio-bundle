<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Bookmark\Contract;

// Compatibility alias for existing hosts; implementation lives in bookmark-bundle.
class_alias(\Survos\BookmarkBundle\Contract\BookmarkOwnerInterface::class, __NAMESPACE__.'\\BookmarkOwnerInterface');
