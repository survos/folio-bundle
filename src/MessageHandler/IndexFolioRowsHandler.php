<?php

declare(strict_types=1);

namespace Survos\FolioBundle\MessageHandler;

use Psr\Log\LoggerInterface;
use Survos\FolioBundle\Message\IndexFolioRowsMessage;
use Survos\FolioBundle\Service\FolioSearchIndex;
use Survos\FolioBundle\Service\FolioSearchProjector;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Indexes and verifies one published folio; a folio that does not verify fails the message, so it is retried. */
#[AsMessageHandler]
final readonly class IndexFolioRowsHandler
{
    public function __construct(
        private FolioSearchIndex $index,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(IndexFolioRowsMessage $message): void
    {
        // Publication queues every built locale; a translation shares its source's rows and is not
        // indexed on its own. Not a failure: nothing to retry.
        if (FolioSearchProjector::isTranslation($message->folioCode)) {
            $this->logger?->info('Folio translation not indexed on its own', ['folio' => $message->folioCode]);

            return;
        }
        $result = $this->index->index($message->folioCode);
        unset($result['verify']);
        $this->logger?->info('Folio indexed and verified in Elasticsearch', ['folio' => $message->folioCode] + $result);
    }
}
