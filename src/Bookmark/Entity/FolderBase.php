<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Bookmark\Entity;

// Load the legacy enum before PHP checks host constructor argument types.
enum_exists(\Survos\FolioBundle\Bookmark\Enum\FolderVisibility::class);

// Compatibility alias for existing hosts; implementation lives in bookmark-bundle.
class_alias(\Survos\BookmarkBundle\Entity\FolderBase::class, __NAMESPACE__.'\\FolderBase');
