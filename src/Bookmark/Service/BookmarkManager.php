<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Bookmark\Service;

enum_exists(\Survos\FolioBundle\Bookmark\Enum\FolderVisibility::class);

/** Compatibility service for existing host constructor type declarations. */
final class BookmarkManager extends \Survos\BookmarkBundle\Service\BookmarkManager {}
