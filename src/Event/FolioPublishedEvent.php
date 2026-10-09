<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Event;

/** A complete working Folio is closed and atomically installed at its final path. */
final readonly class FolioPublishedEvent
{
    public function __construct(
        public string $datasetKey,
        public string $dbFile,
        public int $rowCount,
        public ?string $locale = null,
    ) {
        $this->folioCode = $datasetKey.($locale !== null ? '.'.$locale : '');
    }

    /** Artifact identity, distinct from the dataset and internal row IDs. */
    public string $folioCode;
}
