<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Survos\DuckDbBundle\ConnectionPool;
use Survos\FolioBundle\Catalog\DatasetField;
use Survos\FolioBundle\Catalog\FolioCatalogClient;
use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Survos\FolioBundle\Event\FolioPublicationChangedEvent;
use Survos\FolioBundle\Service\DuckDbFolioReader;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FolioPublicationInvalidationTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir().'/folio-invalidation-'.bin2hex(random_bytes(8)).'.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testEveryWorkerObservesStableUriReplacementAndWithdrawal(): void
    {
        $old = [self::entry('news/a', 'first'), self::entry('news/b', 'unchanged')];
        $new = [self::entry('news/a', 'second'), self::entry('news/b', 'unchanged')];
        $publisher = new FolioCatalogClient(new MockHttpClient([
            new MockResponse(json_encode($old)), new MockResponse(json_encode($new)),
            new MockResponse(json_encode([$new[1]])),
        ]), 'https://harvest.test', $this->file);
        $publisher->refresh();
        $workers = [];
        $events = [[], []];
        foreach ([0, 1] as $index) {
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(FolioPublicationChangedEvent::class, static function ($event) use (&$events, $index): void {
                $events[$index][] = $event;
            });
            $workers[] = new FolioCatalogClient(new MockHttpClient(static fn () => throw new \LogicException('Must use shared cache')),
                'https://harvest.test', $this->file, events: $dispatcher);
            $workers[$index]->all();
        }
        $events = [[], []];
        $publisher->refresh();
        foreach ($workers as $index => $worker) {
            $this->beginRequest($worker);
            self::assertCount(1, $events[$index]);
            $event = $events[$index][0];
            self::assertSame('news/a', $event->datasetKey);
            self::assertSame('first', $event->previous[0]->revision);
            self::assertSame('second', $event->current[0]->revision);
            self::assertTrue($event->affects('s3://bucket/news/a.dfolio'));
            self::assertTrue($event->affects('s3://bucket/news/a.drend'));
            self::assertTrue($event->affects('s3://bucket/news/a.dwords'));
            self::assertFalse($event->affects('s3://bucket/news/b.dfolio'));
            $this->beginRequest($worker);
            self::assertCount(1, $events[$index], 'Unchanged publications must not be evicted again.');
        }
        $publisher->refresh();
        foreach ($workers as $index => $worker) {
            $this->beginRequest($worker);
            self::assertCount(2, $events[$index]);
            self::assertSame([], $events[$index][1]->current);
            self::assertTrue($events[$index][1]->affects('s3://bucket/news/a.dfolio'));
        }
    }

    public function testInvalidationDropsOnlyAffectedPoolOwnership(): void
    {
        $pool = new ConnectionPool();
        $reader = new DuckDbFolioReader($pool);
        // Seed real PDO handles without needing a live S3 endpoint or the native extension.
        $a = $pool->remember('a', static fn () => new \PDO('sqlite::memory:'));
        $b = $pool->remember('b', static fn () => new \PDO('sqlite::memory:'));
        $weak = \WeakReference::create($a);
        $paths = new \WeakMap();
        $paths[$a] = ['a', 's3://bucket/news/a.dfolio'];
        $paths[$b] = ['b', 's3://bucket/news/b.dfolio'];
        (new \ReflectionProperty($reader, 'pooledPaths'))->setValue($reader, $paths);
        unset($a);
        $reader->invalidate(new FolioPublicationChangedEvent('news/a', [], [self::entry('news/a', 'second')]));
        self::assertNull($weak->get(), 'Invalidation bookkeeping must not retain the native connection.');
        self::assertSame(1, $pool->stats()['open']);
        self::assertSame($b, $pool->remember('b', static fn () => throw new \LogicException('Unrelated connection evicted')));
    }

    public function testFailedListenerIsRetried(): void
    {
        $publisher = new FolioCatalogClient(new MockHttpClient([new MockResponse(json_encode([self::entry('news/a', 'first')])), new MockResponse(json_encode([self::entry('news/a', 'second')]))]),
            'https://harvest.test', $this->file);
        $publisher->refresh();
        $dispatcher = new EventDispatcher();
        $worker = new FolioCatalogClient(new MockHttpClient(), 'https://harvest.test', $this->file, events: $dispatcher);
        $worker->all();
        $publisher->refresh();
        $worker->reset();
        $calls = 0;
        $dispatcher->addListener(FolioPublicationChangedEvent::class, static function () use (&$calls): void {
            if (1 === ++$calls) {
                throw new \RuntimeException('Cache unavailable');
            }
        });
        try {
            $worker->all();
            self::fail('Invalidation failure must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('Cache unavailable', $e->getMessage());
        }
        self::assertCount(1, $worker->all());
        self::assertSame(2, $calls);
    }

    private function beginRequest(FolioCatalogClient $worker): void
    {
        $listener = new \Survos\FolioBundle\EventListener\FolioPublicationRefreshListener($worker);
        $listener->onRequest(new \Symfony\Component\HttpKernel\Event\RequestEvent(
            $this->createStub(\Symfony\Component\HttpKernel\HttpKernelInterface::class),
            \Symfony\Component\HttpFoundation\Request::create('/'),
            \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST,
        ));
    }

    private static function entry(string $key, string $revision): FolioCatalogEntry
    {
        return new FolioCatalogEntry($key, 'news', explode('/', $key)[1], $key,
            revision: $revision, artifactType: DatasetField::DUCKDB_TYPE,
            remoteUri: 's3://bucket/'.$key.'.dfolio');
    }
}
