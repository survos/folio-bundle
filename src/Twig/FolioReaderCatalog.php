<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Twig;

use Psr\Log\LoggerInterface;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\FolioBundle\Publisher\Publication;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Reader-owned availability: never infer publication URLs from dataset codes.
 *
 * Two sources, both optional. folio_publications() reads Harvest's publisher receipts
 * (GET /api/datasets/{key}/publications) when the dataset API is configured — the authoritative
 * answer. folio_reader_url() is the older single-reader /folios.json probe (reader_server). With
 * neither configured both answer "nowhere", and pages render without links.
 */
final class FolioReaderCatalog
{
    private ?array $folios = null;

    /** @var array<string, list<Publication>> */
    private array $publications = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly ?string $server = null,
        private readonly ?string $proxy = null,
        private readonly ?DatasetPublicationClient $datasets = null,
    ) {}

    /**
     * Current publications of a dataset's folios, one per URL; stale receipts (an older revision)
     * left out. Outage → [].
     *
     * @return list<Publication>
     */
    #[AsTwigFunction('folio_publications')]
    public function publications(string $datasetKey): array
    {
        if ($this->datasets === null) {
            return [];
        }
        return $this->publications[$datasetKey] ??= $this->cache->get('folio_publications.'.hash('sha256', $this->datasets->source().'|'.$datasetKey), function (ItemInterface $item) use ($datasetKey): array {
            $item->expiresAfter(60);
            try {
                $byUrl = [];
                foreach ($this->datasets->publications($datasetKey) as $publication) {
                    if ($publication->current && preg_match('#^https?://#i', $publication->url)) {
                        $byUrl[$publication->url] ??= $publication;
                    }
                }
                return array_values($byUrl);
            } catch (\Throwable $e) {
                // A page render must not fail because the hub is down; it just shows no links.
                $item->expiresAfter(10);
                $this->logger->warning('Folio publications unavailable', ['datasetKey' => $datasetKey, 'exception' => $e]);
                return [];
            }
        });
    }

    #[AsTwigFunction('folio_reader_url')]
    public function url(string $folioCode): ?string
    {
        if (!$this->server) {
            return null;
        }
        $this->folios ??= $this->cache->get('folio_reader.'.hash('sha256', $this->server), function (ItemInterface $item): array {
            $item->expiresAfter(60);
            try {
                $data = $this->http->request('GET', rtrim($this->server, '/').'/folios.json', [
                    'timeout' => 2, 'max_duration' => 3, 'http_version' => '1.1', 'proxy' => $this->proxy,
                ])->toArray();
                if (!isset($data['folios']) || !is_array($data['folios'])) {
                    throw new \UnexpectedValueException('Reader catalog has no folios map.');
                }
                return $data['folios'];
            } catch (\Throwable $e) {
                $this->logger->warning('Reader catalog unavailable', ['server' => $this->server, 'exception' => $e]);
                return [];
            }
        });
        $path = $this->folios[$folioCode]['path'] ?? null;
        // Catalog paths must stay on the configured reader host.
        if (!is_string($path) || !preg_match('~^/[a-z0-9-]+$~D', $path)) {
            return null;
        }
        return rtrim($this->server, '/').$path;
    }
}
