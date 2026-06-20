<?php

declare(strict_types=1);

namespace Survos\FolioBundle\EventListener;

use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Event\BuildFolioRequestedEvent;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\FolioBundle\Service\FolioIngestService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * (Re)builds a dataset's folio when a stage command asks for it inline (e.g. `dataset:normalize
 * --folio`), so the current folio data is visible right after normalizing — no separate
 * `folio:build` run. Decoupled via {@see BuildFolioRequestedEvent} because dataset-bundle cannot
 * depend on folio-bundle (folio→dataset is the dependency direction).
 */
#[AsEventListener]
final class BuildFolioRequestedListener
{
    public function __construct(
        private readonly DatasetInfoRepository $datasets,
        private readonly FolioIngestService $ingest,
    ) {
    }

    public function __invoke(BuildFolioRequestedEvent $event): void
    {
        $info = $this->datasets->findOneBy(['datasetKey' => $event->datasetKey]);
        if (!$info instanceof DatasetInfo) {
            return;
        }

        // Rows-only (dispatchFinished: false) — the finished event would open a SECOND folio
        // connection (FTS indexer) while this ingest still holds one, deadlocking SQLite. Inline we
        // just want the current item/dto_data visible; run folio:fts:rebuild if search is needed.
        $this->ingest->ingestDataset($info, $event->core, dispatchFinished: false);
    }
}
