<?php

declare(strict_types=1);

namespace Survos\FolioBundle\EventListener;

use Psr\Log\LoggerInterface;
use Survos\FolioBundle\Event\FolioIngestFinishedEvent;
use Survos\FolioBundle\Message\IndexFolioRowsMessage;
use Survos\FolioBundle\Service\FolioElasticRowIndex;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A build that left text search to Elasticsearch (fts_content = off) queues its rows for the
 * shared row index. Without this the folio's search box has nowhere to go until someone runs
 * `folio:elastic:index` by hand.
 */
final readonly class FolioElasticIndexListener
{
    public function __construct(
        private FolioElasticRowIndex $index,
        private ?MessageBusInterface $bus = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(FolioIngestFinishedEvent $event): void
    {
        if (!$this->index->isConfigured() || !$this->index->wantsIndex($event->dbFile)) {
            return;
        }
        if ($this->bus === null) {
            $this->logger?->warning('Folio has no FTS index and no message bus to queue its Elasticsearch index; run folio:elastic:index', [
                'folio' => $event->datasetKey,
            ]);

            return;
        }
        $this->bus->dispatch(new IndexFolioRowsMessage($event->datasetKey));
    }
}
