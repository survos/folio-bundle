<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\FolioBundle\Twig\FolioReaderCatalog;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** folio/show links only to confirmed, current publications, and renders fine without any. */
final class FolioPublicationsTest extends TestCase
{
    public function testNothingConfiguredMeansNoLinks(): void
    {
        $catalog = new FolioReaderCatalog(new MockHttpClient([]), new ArrayAdapter(), new NullLogger());

        self::assertSame([], $catalog->publications('loc/one'));
        self::assertNull($catalog->url('loc/one'), 'reader_server is no longer configured in Harvest');
    }

    public function testOnlyCurrentPublicationsOncePerUrl(): void
    {
        $requested = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested = $url;
            return new MockResponse(json_encode(['version' => 1, 'datasetKey' => 'loc/one', 'publications' => [
                self::publication('ink', 'Ink', 'https://inkstory.org/one', true),
                self::publication('ink', 'Ink', 'https://inkstory.org/one', true, 'folio'),
                self::publication('zm', null, 'https://museado.org/loc/one', true),
                self::publication('vox', 'Vox', 'https://vox.example/one', false),
            ]], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        });
        $catalog = $this->catalog($http);

        $publications = $catalog->publications('loc/one');

        self::assertSame('https://harvest.example/api/datasets/loc/one/publications', $requested);
        self::assertSame(['Ink' => 'https://inkstory.org/one', 'zm' => 'https://museado.org/loc/one'],
            array_combine(array_map(static fn ($p) => $p->name(), $publications), array_map(static fn ($p) => $p->url, $publications)));
    }

    public function testAnOutageRendersNoLinksInsteadOfFailing(): void
    {
        $catalog = $this->catalog(new MockHttpClient([new MockResponse('', ['error' => 'connection refused'])]));

        self::assertSame([], $catalog->publications('loc/one'));
    }

    private function catalog(MockHttpClient $http): FolioReaderCatalog
    {
        return new FolioReaderCatalog($http, new ArrayAdapter(), new NullLogger(),
            datasets: new DatasetPublicationClient($http, 'https://harvest.example', 'read'));
    }

    private static function publication(string $publisher, ?string $label, string $url, bool $current, string $type = 'folio_archive'): array
    {
        return ['publisher' => $publisher, 'environment' => 'prod', 'label' => $label, 'artifactType' => $type,
            'artifactCode' => 'default', 'url' => $url, 'revision' => 'r1', 'current' => $current,
            'occurredAt' => '2026-10-10T11:30:00.000000Z', 'confirmedAt' => '2026-10-10T11:30:01.000000Z'];
    }
}
