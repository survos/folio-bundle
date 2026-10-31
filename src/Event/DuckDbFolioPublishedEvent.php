<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Event;

/** A logical DuckDB snapshot has been atomically published and registered. */
final readonly class DuckDbFolioPublishedEvent
{
    public function __construct(public string $folioCode)
    {
    }
}
