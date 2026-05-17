<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Event;

use Survos\FolioBundle\Model\FolioSummary;

final readonly class FolioIngestFinishedEvent
{
    public function __construct(
        public string $datasetKey,
        public string $core,
        public string $dbFile,
        public string $sourceFile,
        public FolioSummary $summary,
    ) {
    }
}
