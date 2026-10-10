<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Publisher;

enum SelectionMode: string
{
    case All = 'all';
    case Tags = 'tags';
    case Datasets = 'datasets';
    /** Whatever the publisher confirms. */
    case Manual = 'manual';
}
