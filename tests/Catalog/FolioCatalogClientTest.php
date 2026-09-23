<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Catalog\FolioCatalogClient;
use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The behaviours that made three apps write three clients.
 */
final class FolioCatalogClientTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = sys_get_temp_dir() . '/folio-catalog-' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheFile);
    }

    public function testReadsBothPublishedShapes(): void
    {
        // museado.org serves a bare array; a local zm serves {"folios": [...]}. A reader that
        // assumes one of them silently sees an empty catalog against the other.
        foreach ([self::rows(), ['folios' => self::rows()]] as $payload) {
            $client = $this->client([new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), [
                'response_headers' => ['content-type' => 'application/json'],
            ])]);

            self::assertCount(2, $client->all());
        }
    }

    public function testEntryIsTypedAndDerivesProviderAndCode(): void
    {
        $client = $this->client([$this->ok(self::rows())]);

        $entry = $client->find('loc/voices-remembering-slavery');
        self::assertInstanceOf(FolioCatalogEntry::class, $entry);
        self::assertSame('loc', $entry->provider);
        // 'code' is absent from this row: derived from the key rather than demanded of the payload.
        self::assertSame('voices-remembering-slavery', $entry->code);
        self::assertSame(['oral-history'], $entry->tags);
        self::assertTrue($entry->hasTag('Oral-History'));
        self::assertSame(10, $entry->rowCount);
    }

    public function testRowWithoutADatasetKeyIsSkippedNotFaked(): void
    {
        $client = $this->client([$this->ok([['title' => 'No identity'], ...self::rows()])]);

        self::assertCount(2, $client->all());
    }

    public function testAnOutageKeepsTheLastGoodCatalogAndSaysSo(): void
    {
        // The distinction the brief asks for: an unreachable hub must not read as "this site has
        // no folios", which would unpublish everything a set selects.
        $client = $this->client([$this->ok(self::rows())]);
        self::assertCount(2, $client->all());
        self::assertFalse($client->isStale());

        $afterOutage = $this->client([new MockResponse('', ['error' => 'connection refused'])], ttl: 0);
        self::assertCount(2, $afterOutage->all(), 'the cached catalog should survive the outage');
        self::assertTrue($afterOutage->isStale());
    }

    public function testAnAuthoritativeEmptyCatalogIsNotStale(): void
    {
        $this->client([$this->ok(self::rows())])->all();

        $client = $this->client([$this->ok([])], ttl: 0);
        self::assertSame([], $client->all());
        self::assertFalse($client->isStale(), 'an empty answer from a reachable hub is an answer');
    }

    public function testCacheFromAnotherHubIsIgnored(): void
    {
        file_put_contents($this->cacheFile, json_encode([
            'source' => 'https://elsewhere.example/folio/list.json',
            'fetchedAt' => time(),
            'folios' => self::rows(),
        ], JSON_THROW_ON_ERROR));

        $client = $this->client([$this->ok([])]);
        self::assertSame([], $client->all(), 'another server\'s catalog is not this one\'s');
    }

    public function testMatchingUsesFolioSetCriteria(): void
    {
        $client = $this->client([$this->ok(self::rows())]);

        self::assertSame(
            ['loc/voices-remembering-slavery'],
            array_map(static fn (FolioCatalogEntry $e): string => $e->datasetKey, $client->matching(['tags' => ['oral-history']])),
        );
        self::assertSame(
            ['survey/us-newspapers'],
            array_map(static fn (FolioCatalogEntry $e): string => $e->datasetKey, $client->matching(['provider' => ['survey']])),
        );
        // minRows is the criterion that quietly drops a folio that has not been built yet.
        self::assertCount(1, $client->matching(['minRows' => 100]));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        return [
            [
                'datasetKey' => 'loc/voices-remembering-slavery',
                'title' => 'Voices Remembering Slavery',
                'tags' => ['Oral-History'],
                'rowCount' => 10,
                'contentType' => 'interview',
                'downloadUrl' => '/folio/archive/loc/voices-remembering-slavery.folio.gz',
            ],
            [
                'datasetKey' => 'survey/us-newspapers',
                'provider' => 'survey',
                'code' => 'us-newspapers',
                'title' => 'US Newspaper Survey',
                'tags' => ['newspaper-source'],
                'rowCount' => 11479,
                'contentType' => 'newspaper',
            ],
        ];
    }

    /** @param array<mixed> $payload */
    private function ok(array $payload): MockResponse
    {
        return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), [
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /** @param list<MockResponse> $responses */
    private function client(array $responses, int $ttl = 300): FolioCatalogClient
    {
        return new FolioCatalogClient(
            new MockHttpClient($responses),
            'https://museado.org',
            $this->cacheFile,
            $ttl,
        );
    }
}
