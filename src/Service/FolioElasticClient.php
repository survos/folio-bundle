<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Plain-HTTP Elasticsearch for folio-bundle: the pooled builds and the per-folio row index.
 *
 * Not `elasticsearch/elasticsearch`: every call here is a JSON body or NDJSON over one request, and
 * folio-bundle is installed in apps with no Elasticsearch client, where adding one as a hard
 * dependency to make a few commands work is the wrong trade. Reads the same DSN search-bundle's
 * adapter does — `elasticsearch://host:port`, `elasticsearch+https://…`, `?api_key=`, `?ca=`.
 */
final class FolioElasticClient
{
    private ?string $base = null;

    /** @var array<string, string> */
    private array $headers = [];

    /** Set from the DSN's ?ca= when the node uses a private CA, as fsn1's does. */
    private ?string $caBundle = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(default::ELASTICSEARCH_DSN)%')] private readonly ?string $dsn = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return ($this->dsn ?? '') !== '';
    }

    /**
     * @param array<string, string> $headers extra headers, e.g. an NDJSON Content-Type
     * @param array<string, mixed>|null $json
     * @param list<int> $expected HTTP codes that are not failures; empty means 2xx only
     * @return array<string, mixed>|int decoded body, or the status code when $decode is false
     */
    public function request(
        string $method,
        string $path,
        ?array $json = null,
        array $expected = [],
        bool $decode = false,
        ?string $raw = null,
        array $headers = [],
        float $timeout = 120,
    ): array|int {
        $this->endpoint();
        $options = ['headers' => $headers + $this->headers, 'timeout' => $timeout];
        if ($this->caBundle !== null) {
            $options['cafile'] = $this->caBundle;
        }
        if ($json !== null) {
            $options['json'] = $json;
        }
        if ($raw !== null) {
            $options['body'] = $raw;
        }
        $url = $this->base.'/'.ltrim($path, '/');
        $response = $this->http->request($method, $url, $options);
        $status = $response->getStatusCode();
        if ($expected !== [] && in_array($status, $expected, true)) {
            return $decode ? $response->toArray(false) : $status;
        }
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Elasticsearch %s %s → %d: %s', $method, $url, $status,
                substr($response->getContent(false), 0, 400)));
        }

        return $decode ? $response->toArray(false) : $status;
    }

    private function endpoint(): void
    {
        if ($this->base !== null) {
            return;
        }
        if (!$this->isConfigured()) {
            throw new \LogicException('Set ELASTICSEARCH_DSN, e.g. elasticsearch://127.0.0.1:9200');
        }
        $parts = parse_url((string) $this->dsn);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new \InvalidArgumentException(sprintf('Invalid Elasticsearch DSN "%s".', $this->dsn));
        }
        $scheme = ($parts['scheme'] ?? '') === 'elasticsearch+https' ? 'https' : 'http';
        parse_str($parts['query'] ?? '', $query);
        $this->headers = ['Accept' => 'application/json'];
        if (is_string($query['api_key'] ?? null) && $query['api_key'] !== '') {
            $this->headers['Authorization'] = 'ApiKey '.$query['api_key'];
        }
        $this->caBundle = is_string($query['ca'] ?? null) && $query['ca'] !== '' ? $query['ca'] : null;
        $this->base = sprintf('%s://%s:%d', $scheme, $parts['host'], $parts['port'] ?? 9200);
    }
}
