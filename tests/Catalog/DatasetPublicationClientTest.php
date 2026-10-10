<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Catalog\ArtifactChecksumException;
use Survos\FolioBundle\Catalog\DatasetField;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\FolioBundle\Catalog\FolioCatalogClient;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DatasetPublicationClientTest extends TestCase
{
    private const SERVER = 'https://harvest.example';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/folio-publication-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testExpiredCursorFallsBackToAFullSyncInsteadOfFailingEveryRun(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = substr($url, strlen(self::SERVER));
            return match (true) {
                str_contains($url, 'after=old:7') => self::json(['error' => 'perform a full sync'], 410),
                str_ends_with($url, '/api/changes') => self::json(self::feed('new:3', [])),
                str_contains($url, 'after=new:3') => self::json(self::feed('new:3', [])),
                str_ends_with($url, '/api/datasets') => self::json([DatasetField::VERSION => 1, DatasetField::ITEMS => [self::dataset('mus/a')], DatasetField::NEXT => null]),
                default => throw new \LogicException('Unexpected request '.$url),
            };
        });
        $catalog = new FolioCatalogClient($http, '', $this->dir.'/catalog.json', datasets: new DatasetPublicationClient($http, self::SERVER, 'token'));
        $saved = [DatasetField::VERSION => 1, DatasetField::SOURCE => self::SERVER, DatasetField::CURSOR => 'old:7',
            DatasetField::ITEMS => ['mus/gone' => self::dataset('mus/gone')]];

        $applied = null;
        $checkpoint = $catalog->synchronize($saved, false, static function (array $entries) use (&$applied): void { $applied = $entries; });

        self::assertSame('new:3', $checkpoint[DatasetField::CURSOR], 'the checkpoint moves to the new registry head');
        self::assertSame(['mus/a'], array_keys($checkpoint[DatasetField::ITEMS]), 'the stale map is replaced, not patched');
        self::assertSame(['mus/a'], array_map(static fn ($entry): string => $entry->datasetKey, $applied));
        self::assertSame(['/api/changes?after=old:7', '/api/changes', '/api/datasets', '/api/changes?after=new:3'], $requests);
    }

    public function testA410DuringAFullSyncIsNotRetriedForever(): void
    {
        $http = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_ends_with($url, '/api/changes')
            ? self::json(self::feed('new:3', []))
            : self::json(['error' => 'gone'], 410));

        $this->expectException(ClientException::class);
        (new DatasetPublicationClient($http, self::SERVER, 'token'))->changes(null, true);
    }

    public function testOtherFeedErrorsStillFailTheRun(): void
    {
        $http = new MockHttpClient([self::json(['error' => 'bad cursor'], 400)]);
        $saved = [DatasetField::VERSION => 1, DatasetField::SOURCE => self::SERVER, DatasetField::CURSOR => 'old:7', DatasetField::ITEMS => []];

        $this->expectException(ClientException::class);
        (new DatasetPublicationClient($http, self::SERVER, 'token'))->changes($saved, false);
    }

    public function testDownloadKeepsBytesThatMatchTheProviderChecksum(): void
    {
        $bytes = random_bytes(4096);
        $sent = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($bytes, &$sent): MockResponse {
            $sent = $options['normalized_headers']['authorization'][0] ?? null;
            return new MockResponse($bytes, ['response_headers' => ['X-Artifact-Sha256' => strtoupper(hash('sha256', $bytes))]]);
        });

        $written = (new DatasetPublicationClient($http, self::SERVER, 'token'))->download(self::artifactUrl(), $this->dir.'/a.folio.gz');

        self::assertSame(strlen($bytes), $written);
        self::assertSame($bytes, file_get_contents($this->dir.'/a.folio.gz'));
        self::assertSame('Authorization: Bearer token', $sent);
    }

    public function testDownloadRejectsBytesThatDoNotMatchAndLeavesNothingBehind(): void
    {
        $http = new MockHttpClient([new MockResponse('truncated', ['response_headers' => ['X-Artifact-Sha256' => hash('sha256', 'the real bytes')]])]);
        $catalog = new FolioCatalogClient($http, '', $this->dir.'/catalog.json', datasets: new DatasetPublicationClient($http, self::SERVER, 'token'));

        try {
            $catalog->download(self::SERVER.self::artifactUrl(), $this->dir.'/a.folio.gz');
            self::fail('A checksum mismatch must fail the entry.');
        } catch (ArtifactChecksumException $mismatch) {
            self::assertStringContainsString('mismatch', $mismatch->getMessage());
        }
        self::assertSame([], glob($this->dir.'/a.folio.gz*'));
    }

    public function testDownloadWithoutAChecksumHeaderIsRefused(): void
    {
        $http = new MockHttpClient([new MockResponse('bytes')]);

        $this->expectException(ArtifactChecksumException::class);
        try {
            (new DatasetPublicationClient($http, self::SERVER, 'token'))->download(self::artifactUrl(), $this->dir.'/a.folio.gz');
        } finally {
            self::assertSame([], glob($this->dir.'/a.folio.gz*'));
        }
    }

    private static function artifactUrl(): string
    {
        return '/api/datasets/mus/a/artifacts/1/download?revision=r1';
    }

    private static function feed(string $cursor, array $items): array
    {
        return [DatasetField::VERSION => 1, DatasetField::ITEMS => $items, DatasetField::CURSOR => $cursor,
            DatasetField::THROUGH => $cursor, DatasetField::HAS_MORE => false];
    }

    private static function dataset(string $key): array
    {
        return [DatasetField::DATASET_KEY => $key, 'label' => $key, 'description' => null, DatasetField::PROVIDER => explode('/', $key)[0],
            'tags' => [], 'contentType' => null, 'rowCount' => 1, DatasetField::ARTIFACTS => [[
                'type' => DatasetField::FOLIO_ARCHIVE_TYPE, DatasetField::CODE => DatasetField::DEFAULT_CODE, DatasetField::REVISION => 'r1',
                DatasetField::CHECKSUM => null, DatasetField::SIZE_BYTES => 1, DatasetField::UPDATED_AT => null, DatasetField::COMPRESSED => true,
                DatasetField::DOWNLOAD_URL => '/api/datasets/'.$key.'/artifacts/1/download?revision=r1',
            ]]];
    }

    private static function json(array $payload, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
