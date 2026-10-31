<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Path\DataPaths;
use Survos\FolioBundle\Event\DuckDbFolioPublishedEvent;
use Survos\FolioBundle\Event\FolioPublishedEvent;
use Survos\FolioBundle\EventListener\FolioElasticIndexListener;
use Survos\FolioBundle\Message\IndexFolioRowsMessage;
use Survos\FolioBundle\Service\FolioElasticClient;
use Survos\FolioBundle\Service\FolioFtsIndexer;
use Survos\FolioBundle\Service\FolioSchemaManager;
use Survos\FolioBundle\Service\FolioSearchIndex;
use Survos\FolioBundle\Service\FolioSearchProjector;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class FolioElasticIndexListenerTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('sources')]
    public function testOnlyCompletedConfiguredSourceQueuesIndexing(string $source): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getEventManager')->willReturn(new EventManager());
        $folios = new FolioService($em, new FolioSchemaManager(new ArrayAdapter()), new DataPaths(sys_get_temp_dir()));
        $index = new FolioSearchIndex($folios, new FolioSearchProjector(new FolioFtsIndexer()),
            new FolioElasticClient(new MockHttpClient(), 'elasticsearch://localhost:9200'), source: $source);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (IndexFolioRowsMessage $message): bool => 'test/paper' === $message->folioCode,
        ))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $listener = new FolioElasticIndexListener($index, $bus, enabled: true, source: $source);
        $listener(new FolioPublishedEvent('test/paper', '/unused.folio', 3));
        $listener(new DuckDbFolioPublishedEvent('test/paper'));
        $listener(new FolioPublishedEvent('test/paper', '/unused.en.folio', 3, 'en'));
        $listener(new DuckDbFolioPublishedEvent('test/paper.en'));
    }

    public static function sources(): iterable
    {
        yield ['sqlite'];
        yield ['duckdb'];
    }
}
