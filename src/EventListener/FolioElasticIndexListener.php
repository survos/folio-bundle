<?php

declare(strict_types=1);

namespace Survos\FolioBundle\EventListener;

use Psr\Log\LoggerInterface;
use Survos\FolioBundle\Event\FolioPublishedEvent;
use Survos\FolioBundle\Message\IndexFolioRowsMessage;
use Survos\FolioBundle\Service\FolioElasticRowIndex;
use Symfony\Component\Messenger\MessageBusInterface;

/** Producer-only indexing, after atomic publication; readers do not enqueue writes. */
final readonly class FolioElasticIndexListener
{
    public function __construct(
        private FolioElasticRowIndex $index,
        private ?MessageBusInterface $bus = null,
        private ?LoggerInterface $logger = null,
        private bool $enabled = false,
    ) {
    }

    public function __invoke(FolioPublishedEvent $event): void
    {
        if (!$this->enabled || !$this->index->isConfigured()) {
            return;
        }
        if ($this->bus === null) {
            $this->logger?->warning('Folio producer has no message bus to queue its Elasticsearch index; run folio:elastic:index', [
                'folio' => $event->folioCode,
            ]);

            return;
        }
        $this->bus->dispatch(new IndexFolioRowsMessage($event->folioCode));
    }
}
