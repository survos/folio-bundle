<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Bookmark\Enum;

enum FolderVisibility: string
{
    case Public = 'public';
    case Unlisted = 'unlisted';
    case Private = 'private';
}
