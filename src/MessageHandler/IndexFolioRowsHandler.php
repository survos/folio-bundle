<?php

declare(strict_types=1);

namespace Survos\FolioBundle\MessageHandler;

use Psr\Log\LoggerInterface;
use Survos\FolioBundle\Message\IndexFolioRowsMessage;
use Survos\FolioBundle\Service\FolioElasticRowIndex;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class IndexFolioRowsHandler
{
    public function __construct(
        private FolioElasticRowIndex $index,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(IndexFolioRowsMessage $message): void
    {
        $result = $this->index->index($message->folioCode);
        $this->logger?->info('Folio rows indexed in Elasticsearch', ['folio' => $message->folioCode] + $result);
    }
}
