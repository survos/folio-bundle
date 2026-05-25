<?php

declare(strict_types=1);

namespace Survos\FolioBundle\EventListener;

use Psr\Log\LoggerInterface;
use Survos\FolioBundle\Event\FolioIngestFinishedEvent;
use Survos\FolioBundle\Service\{FolioArchivePreparer,FolioFtsIndexer};

final readonly class FolioFtsIndexListener
{
    public function __construct(
        private FolioArchivePreparer $preparer,
        private FolioFtsIndexer $indexer,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(FolioIngestFinishedEvent $event): void
    {
        $archive = $this->preparer->prepare($event->dbFile, [
            'datasetKey' => $event->datasetKey,
            'core' => $event->core,
            'sourceFile' => basename($event->sourceFile),
        ]);
        $result = $this->indexer->rebuild($event->dbFile);
        $this->logger?->info('Folio FTS5 index rebuilt', [
            'dataset' => $event->datasetKey,
            'core' => $event->core,
            'schemaTables' => $archive['tables'] ?? 0,
            'schemaProperties' => $archive['properties'] ?? 0,
            'views' => $archive['views'] ?? 0,
            'docs' => $archive['docs'] ?? 0,
            'rows' => $result['rows'],
            'bytes' => $result['bytes'],
            'dbFile' => $event->dbFile,
        ]);
    }
}
