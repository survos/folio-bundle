<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Twig;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Attribute\AsTwigFunction;

/** Reader-owned availability: never infer publication URLs from dataset codes. */
final class FolioReaderCatalog
{
    private ?array $folios = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly ?string $server = null,
        private readonly ?string $proxy = null,
    ) {}

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
