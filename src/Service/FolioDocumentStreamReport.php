<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/** Counts filled in while a FolioDocumentStream generator is consumed. */
final class FolioDocumentStreamReport
{
    public int $count = 0;

    /** @var array<string,int> folioCode => rows streamed */
    public array $perFolio = [];

    /** @var array<string,string> folioCode => why the folio could not be read */
    public array $failed = [];
}
