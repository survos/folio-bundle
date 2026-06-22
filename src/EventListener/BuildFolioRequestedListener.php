<?php

declare(strict_types=1);

namespace Survos\FolioBundle\EventListener;

use Survos\DatasetBundle\Entity\Artifact;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Event\BuildFolioRequestedEvent;
use Survos\DatasetBundle\Event\DatasetArtifactUpdatedEvent;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\FolioBundle\Service\FolioArchivePreparer;
use Survos\FolioBundle\Service\FolioArchiveService;
use Survos\FolioBundle\Service\FolioIngestService;
use Survos\FolioBundle\Service\FolioService;
use Survos\JsonlBundle\Service\JsonlStateService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * (Re)builds a dataset's folio when a stage command asks for it inline (e.g. `dataset:normalize
 * --folio`), so the current folio is browsable right after normalizing — no separate `folio:build`
 * run. Decoupled via {@see BuildFolioRequestedEvent} because dataset-bundle cannot depend on
 * folio-bundle (folio→dataset is the dependency direction).
 *
 * This runs the SAME producing path as {@see \Survos\FolioBundle\Command\FolioBuildCommand} (ingest
 * → inflate working folio → register the FOLIO artifact), minus the optional .gz archive — so a
 * fresh, indexed, browsable folio actually appears (the previous rows-only ingest never inflated a
 * working copy, so nothing browsable was produced).
 */
#[AsEventListener]
final class BuildFolioRequestedListener
{
    public function __construct(
        private readonly DatasetInfoRepository $datasets,
        private readonly FolioIngestService $ingest,
        private readonly FolioService $folios,
        private readonly FolioArchivePreparer $preparer,
        private readonly FolioArchiveService $archiveService,
        private readonly JsonlStateService $jsonlState,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(BuildFolioRequestedEvent $event): void
    {
        $info = $this->datasets->findOneBy(['datasetKey' => $event->datasetKey]);
        if (!$info instanceof DatasetInfo) {
            return;
        }

        // Release every handle left open by a previous inline build before touching this folio:
        // the folio sqlite connection (drops its -wal/-shm) and all cached sidecar handles. Same
        // hygiene FolioBuildCommand applies at the top of each dataset iteration.
        $this->folios->closeActive();
        $this->jsonlState->closeAll();

        // No normalized source → nothing to build. The stage either skipped this dataset (no raw)
        // or normalize produced no core; either way there are no rows to fold into a folio.
        if ($this->ingest->resolveSources($info, $event->core) === []) {
            return;
        }

        // Step 1: rows-only ingest. dispatchFinished:false — the finished event would open a SECOND
        // folio connection (FTS indexer) while we still hold one for the inflate below, deadlocking
        // SQLite. inflate() rebuilds the FTS itself, so nothing is lost.
        $result = $this->ingest->ingestDataset($info, $event->core, dispatchFinished: false);

        // Never publish an empty folio: 0 rows means normalize produced nothing for this dataset.
        if ((int) ($result['rows'] ?? 0) === 0) {
            return;
        }

        // Step 2: inflate the working folio (indexes + FTS + views) so it is immediately browsable.
        // prepare() writes the schema snapshot inflate() needs for its views (the inflate-only path
        // FolioBuildCommand takes when --gz is off).
        $workingPath = $this->folios->path($event->datasetKey);
        $this->preparer->prepare($workingPath, ['builtAt' => gmdate(DATE_ATOM)]);
        $this->archiveService->inflate($workingPath);

        // Register the FOLIO artifact so DatasetInfo (and the homepage) reflect the fresh build,
        // mirroring folio:build's event-driven registry update.
        $this->dispatcher->dispatch(new DatasetArtifactUpdatedEvent(
            datasetKey: $event->datasetKey,
            type: Artifact::TYPE_FOLIO,
            uri: $workingPath,
            rowCount: (int) ($result['rows'] ?? 0),
        ));
    }
}
