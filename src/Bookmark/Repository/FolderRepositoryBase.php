<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Bookmark\Repository;

// Compatibility alias for existing hosts; implementation lives in bookmark-bundle.
class_alias(\Survos\BookmarkBundle\Repository\FolderRepositoryBase::class, __NAMESPACE__.'\\FolderRepositoryBase');
