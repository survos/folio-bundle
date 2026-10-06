<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Enum;

use Survos\Folio\Enum\PageType as FolioPageType;

// Backward-compatible name for consumers upgrading from folio-bundle's enum.
class_alias(FolioPageType::class, __NAMESPACE__ . '\\PageType');
