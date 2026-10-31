<?php

declare(strict_types=1);

namespace Survos\FolioBundle\EventListener;

use Psr\Log\LoggerInterface;
use Survos\FolioBundle\Event\DuckDbFolioPublishedEvent;
use Survos\FolioBundle\Event\FolioPublishedEvent;
use Survos\FolioBundle\Message\IndexFolioRowsMessage;
use Survos\FolioBundle\Service\FolioSearchIndex;
use Symfony\Component\Messenger\MessageBusInterface;

/** Producer-only indexing, after atomic publication; readers do not enqueue writes. */
final readonly class FolioElasticIndexListener
{
    public function __construct(
        private FolioSearchIndex $index,
        private ?MessageBusInterface $bus = null,
        private ?LoggerInterface $logger = null,
        private bool $enabled = false,
        private string $source = 'sqlite',
    ) {
    }

    public function __invoke(FolioPublishedEvent|DuckDbFolioPublishedEvent $event): void
    {
        if (!$this->enabled || !$this->index->isConfigured()) {
            return;
        }
        if (($event instanceof DuckDbFolioPublishedEvent) !== ('duckdb' === $this->source)) {
            return;
        }
        // A translation carries its source's row ids: indexing it would overwrite the source rows.
        if (\Survos\FolioBundle\Service\FolioSearchProjector::isTranslation($event->folioCode)) {
            return;
        }
        if (null === $this->bus) {
            $this->logger?->warning('Folio producer has no message bus to queue its Elasticsearch index; run folio:elastic:index', [
                'folio' => $event->folioCode,
            ]);

            return;
        }
        $this->bus->dispatch(new IndexFolioRowsMessage($event->folioCode));
    }
}
