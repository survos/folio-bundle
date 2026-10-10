<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Publisher;

use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Survos\FolioBundle\Publisher\PublisherApiClient;
use Survos\FolioBundle\Publisher\PublisherRegistrar;
use Survos\FolioBundle\Publisher\PublisherState;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The client rules from Harvest's "Publisher registration and receipts" contract, against a fake
 * Harvest that records every request.
 */
final class PublisherRegistrarTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: array}> */
    private array $requests = [];

    /** @var list<callable(string, string, array): MockResponse> */
    private array $script = [];

    public function testFirstRunResetsAndAsksForAFullSync(): void
    {
        $this->script = [fn () => $this->json(self::registration(3))];

        $state = $this->registrar()->begin(null);

        self::assertSame(3, $state->generation());
        self::assertTrue($state->resyncRequired());
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertStringEndsWith('/api/publishers/ink/prod/reset', $this->requests[0]['url']);
        self::assertSame(['label' => 'Ink', 'baseUrl' => 'https://inkstory.org', 'selection' => ['mode' => 'all']], $this->requests[0]['body']);
    }

    public function testConfirmIsOneOperationAndAnUnchangedFolioIsNotResent(): void
    {
        $this->script = [fn (string $m, string $u, array $body) => $this->ack($body)];
        $registrar = $this->registrar();
        $state = new PublisherState(3);

        self::assertTrue($registrar->confirm($state, self::entry('a/one', 'r1'), 'https://inkstory.org/one'));
        $result = $registrar->flush($state);

        self::assertTrue($result->isComplete());
        self::assertSame(['applied' => 1], $result->statuses);
        self::assertSame([], $state->pending());
        self::assertSame(3, $this->requests[0]['body']['generation']);
        self::assertSame([
            'datasetKey' => 'a/one', 'artifactType' => 'folio_archive', 'artifactCode' => 'default', 'state' => 'published',
            'revision' => 'r1', 'url' => 'https://inkstory.org/one', 'occurredAt' => '2026-10-10T12:00:00.000000+00:00',
        ], $this->requests[0]['body']['receipts'][0]);

        // The next incremental run sees the same revision: nothing to say.
        self::assertFalse($registrar->confirm($state, self::entry('a/one', 'r1'), 'https://inkstory.org/one'));
        // Revisions are opaque: any different value is a new confirmation, never "older".
        self::assertTrue($registrar->confirm($state, self::entry('a/one', 'r0'), 'https://inkstory.org/one'));
    }

    public function testAnOutageLosesNoAcknowledgement(): void
    {
        $registrar = $this->registrar();
        $state = new PublisherState(3);
        $registrar->confirm($state, self::entry('a/one', 'r1'), 'https://inkstory.org/one');
        $registrar->confirm($state, self::entry('a/two', 'r9'), 'https://inkstory.org/two');

        $this->script = [fn () => new MockResponse('', ['error' => 'connection refused'])];
        $result = $registrar->flush($state);
        self::assertFalse($result->isComplete());
        self::assertSame(2, $result->remaining);
        self::assertNotNull($result->error);

        // The consumer saves its checkpoint and the process dies; the next run reloads it.
        $saved = json_decode(json_encode($state->toArray(), JSON_THROW_ON_ERROR), true);
        $reloaded = PublisherState::fromArray($saved);
        $sentBefore = $this->requests[0]['body']['receipts'] ?? null;

        $this->script = [fn (string $m, string $u, array $body) => $this->ack($body, 'unchanged')];
        $result = $this->registrar()->flush($reloaded);

        self::assertTrue($result->isComplete());
        self::assertSame(['unchanged' => 2], $result->statuses);
        self::assertSame([], $reloaded->pending());
        self::assertSame('r1', $reloaded->confirmedRevision('a/one', 'folio_archive'));
        // Resent unchanged, including the original occurredAt, so Harvest can order it.
        self::assertSame($sentBefore, end($this->requests)['body']['receipts']);
    }

    public function testA409AfterAResetNeverResurrectsPreResetReceipts(): void
    {
        $registrar = $this->registrar();
        $state = new PublisherState(3);
        $registrar->confirm($state, self::entry('a/one', 'r1'), 'https://inkstory.org/one');

        // Someone reset the publisher in between: receipts → 409, registration now says 4.
        $this->script = [
            fn () => $this->json(['error' => 'stale generation'], 409),
            fn () => $this->json(self::registration(4)),
        ];
        $result = $registrar->flush($state);

        self::assertTrue($result->staleGeneration);
        self::assertSame(4, $state->generation());
        self::assertTrue($state->resyncRequired());
        self::assertSame([], $state->pending(), 'pre-reset receipts are discarded');
        self::assertNull($state->confirmedRevision('a/one', 'folio_archive'));
        self::assertCount(2, $this->requests);
        self::assertSame('GET', $this->requests[1]['method']);

        // Nothing is resent under the new generation until the full sync confirms afresh.
        $this->script = [fn (string $m, string $u, array $body) => $this->ack($body)];
        self::assertSame(0, $registrar->flush($state)->batches);
        $registrar->confirm($state, self::entry('a/one', 'r1'), 'https://inkstory.org/one');
        $registrar->flush($state);
        self::assertSame(4, end($this->requests)['body']['generation']);
    }

    public function testA409WhileHarvestIsUnreachableStillDropsTheQueueAndRecoversNextRun(): void
    {
        $registrar = $this->registrar();
        $state = new PublisherState(3);
        $registrar->confirm($state, self::entry('a/one', 'r1'), 'https://inkstory.org/one');
        $this->script = [
            fn () => $this->json(['error' => 'stale generation'], 409),
            fn () => new MockResponse('', ['error' => 'connection refused']),
        ];
        $registrar->flush($state);
        self::assertSame([], $state->pending());

        $this->script = [fn () => $this->json(self::registration(4))];
        $next = $this->registrar()->begin($state->toArray());
        self::assertSame(4, $next->generation());
        self::assertTrue($next->resyncRequired());
    }

    public function testBeginAdoptsANewerGenerationAndDropsTheQueue(): void
    {
        $state = new PublisherState(3);
        $this->registrar()->confirm($state, self::entry('a/one', 'r1'), 'https://inkstory.org/one');
        $state->markResynced();

        $this->script = [fn () => $this->json(self::registration(5))];
        $next = $this->registrar()->begin($state->toArray());

        self::assertSame(5, $next->generation());
        self::assertSame([], $next->pending());
        self::assertTrue($next->resyncRequired());
    }

    public function testBeginWhileHarvestIsDownKeepsTheQueue(): void
    {
        $state = new PublisherState(3);
        $this->registrar()->confirm($state, self::entry('a/one', 'r1'), 'https://inkstory.org/one');
        $state->markResynced();

        $this->script = [fn () => new MockResponse('', ['error' => 'connection refused'])];
        $next = $this->registrar()->begin($state->toArray());

        self::assertSame(3, $next->generation());
        self::assertCount(1, $next->pending());
        self::assertFalse($next->resyncRequired());
    }

    public function testReceiptsAreBatchedAtFiveHundredAndAFailedBatchKeepsTheRest(): void
    {
        $registrar = $this->registrar();
        $state = new PublisherState(7);
        for ($i = 0; $i < 1201; ++$i) {
            $registrar->confirm($state, self::entry("p/d$i", "r$i"), "https://inkstory.org/d$i");
        }

        $this->script = [
            fn (string $m, string $u, array $body) => $this->ack($body),
            fn () => $this->json(['error' => 'maintenance'], 503),
        ];
        $result = $registrar->flush($state);
        self::assertSame(1, $result->batches);
        self::assertSame(701, $result->remaining);
        self::assertSame('p/d500', $state->pending()[0]->datasetKey, 'order is kept for the retry');

        $ack = fn (string $m, string $u, array $body) => $this->ack($body);
        $this->script = [$ack, $ack];
        $result = $registrar->flush($state);
        self::assertSame(2, $result->batches);
        self::assertTrue($result->isComplete());
        self::assertSame([500, 500, 500, 201], array_map(static fn (array $r): int => count($r['body']['receipts']), array_values(array_filter($this->requests, static fn (array $r): bool => isset($r['body']['receipts'])))));
    }

    public function testWithdrawalIsExplicitAndCarriesNoRevision(): void
    {
        $registrar = $this->registrar();
        $state = new PublisherState(3);
        $registrar->confirm($state, self::entry('a/one', 'r1'), 'https://inkstory.org/one');
        $registrar->confirm($state, self::entry('a/two', 'r2'), 'https://inkstory.org/two');
        $this->script = [fn (string $m, string $u, array $body) => $this->ack($body), fn (string $m, string $u, array $body) => $this->ack($body)];
        $registrar->flush($state);

        // a/two vanished from the feed: the consumer says so; nothing is inferred.
        self::assertSame(1, $registrar->withdrawAbsent($state, [self::entry('a/one', 'r1')]));
        $registrar->flush($state);

        self::assertSame([[
            'datasetKey' => 'a/two', 'artifactType' => 'folio_archive', 'artifactCode' => 'default', 'state' => 'withdrawn',
            'occurredAt' => '2026-10-10T12:00:00.000000+00:00',
        ]], end($this->requests)['body']['receipts']);
        self::assertNull($state->confirmedRevision('a/two', 'folio_archive'));
        self::assertSame('r1', $state->confirmedRevision('a/one', 'folio_archive'));
    }

    public function testUnknownVariantIsDroppedFromConfirmed(): void
    {
        $registrar = $this->registrar();
        $state = new PublisherState(3);
        $registrar->confirm($state, self::entry('gone/one', 'r1'), 'https://inkstory.org/one');
        $this->script = [fn (string $m, string $u, array $body) => $this->ack($body, 'unknown')];

        self::assertSame(['unknown' => 1], $registrar->flush($state)->statuses);
        self::assertSame([], $state->pending());
        self::assertSame([], $state->confirmed());
    }

    public function testAUrlOffTheRegisteredHostIsRejectedBeforeItCanPoisonABatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registrar()->confirm(new PublisherState(3), self::entry('a/one', 'r1'), 'https://elsewhere.example/one');
    }

    private function registrar(): PublisherRegistrar
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $body = isset($options['body']) && $options['body'] !== '' ? json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR) : [];
            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body];
            self::assertContains('Authorization: Bearer ink-secret', $options['headers']);
            $next = array_shift($this->script) ?? throw new \LogicException("Unexpected $method $url");
            return $next($method, $url, $body);
        });
        return new PublisherRegistrar(
            new PublisherApiClient($http, 'https://harvest.example', 'ink-secret', 'ink', 'prod'),
            label: 'Ink',
            baseUrl: 'https://inkstory.org',
            clock: new MockClock('2026-10-10 12:00:00 UTC'),
        );
    }

    private function ack(array $body, string $status = 'applied'): MockResponse
    {
        return $this->json(['version' => 1, 'publisher' => 'ink', 'environment' => 'prod', 'generation' => $body['generation'],
            'results' => array_map(static fn (array $r): array => [
                'datasetKey' => $r['datasetKey'], 'artifactType' => $r['artifactType'], 'artifactCode' => $r['artifactCode'], 'status' => $status,
            ], $body['receipts'])]);
    }

    private function json(array $payload, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), [
            'http_code' => $status, 'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private static function registration(int $generation): array
    {
        return ['version' => 1, 'publisher' => 'ink', 'environment' => 'prod', 'generation' => $generation,
            'label' => 'Ink', 'baseUrl' => 'https://inkstory.org', 'selection' => ['mode' => 'all']];
    }

    private static function entry(string $key, string $revision): FolioCatalogEntry
    {
        [$provider, $code] = explode('/', $key, 2);
        return new FolioCatalogEntry($key, $provider, $code, $key, revision: $revision, artifactType: 'folio_archive');
    }
}
