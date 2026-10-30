<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/** Resolve a folio identity to its trusted working-copy URI; never accept a request path. */
interface DuckDbFolioLocatorInterface
{
    public function locate(string $folioCode): string;
}
