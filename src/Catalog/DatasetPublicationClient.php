<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Catalog;

use Survos\FolioBundle\Publisher\Publication;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Dataset API transport shared by catalog discovery, pull and incremental synchronization. */
final readonly class DatasetPublicationClient
{
    public function __construct(private HttpClientInterface $http, private string $server, private string $token) {}

    public function source(): string { return rtrim($this->server, '/'); }

    public function get(string $path, array $query = []): array { return $this->request($path, $query)->toArray(); }

    private function request(string $path, array $query = []): ResponseInterface
    {
        if ($this->token === '' || !preg_match('#^https?://[^/]+#', $this->server)) {
            throw new \LogicException('Configure the dataset API server and read token.');
        }
        return $this->http->request('GET', $this->source().$path, [
            'auth_bearer' => $this->token, 'query' => $query, 'timeout' => 10, 'max_duration' => 30,
            'headers' => ['Accept' => 'application/json'], 'max_redirects' => 0,
        ]);
    }

    public function dataset(string $key): ?array
    {
        $response = $this->request(self::datasetPath($key));
        return $response->getStatusCode() === 404 ? null : $response->toArray();
    }

    /**
     * Where the dataset's folios are confirmed published (publisher receipts), current or stale.
     *
     * @return list<Publication> empty for an unknown dataset
     */
    public function publications(string $key): array
    {
        $response = $this->request(self::datasetPath($key).'/publications');
        return $response->getStatusCode() === 404 ? [] : array_map(Publication::fromArray(...), array_values($response->toArray()['publications']));
    }

    private static function datasetPath(string $key): string
    {
        return '/api/datasets/'.implode('/', array_map(rawurlencode(...), explode('/', $key)));
    }

    /** @return array<string, array> */
    public function datasets(): array
    {
        $datasets = [];
        $after = null;
        do {
            $page = $this->get('/api/datasets', $after === null ? [] : ['afterKey' => $after]);
            foreach ($page[DatasetField::ITEMS] as $dataset) { $datasets[$dataset[DatasetField::DATASET_KEY]] = $dataset; }
            $next = $page[DatasetField::NEXT];
            if ($next !== null && $after !== null && strcmp($next, $after) <= 0) {
                throw new \UnexpectedValueException('Dataset catalog pagination did not advance.');
            }
            $after = $next;
        } while ($after !== null);
        return $datasets;
    }

    /**
     * Prepare catch-up without advancing a consumer checkpoint. The caller commits after work.
     *
     * A 410 on an incremental replay means the saved cursor belongs to another (or a rebuilt)
     * registry, so the checkpoint is worthless: start over with a full sync instead of failing
     * every scheduled run until someone passes --full by hand.
     */
    public function changes(?array $saved, bool $full): array
    {
        if ($saved !== null && $saved[DatasetField::SOURCE] !== $this->source()) { $saved = null; }
        $full = $full || $saved === null;
        try {
            return $this->catchUp($saved, $full);
        } catch (HttpExceptionInterface $gone) {
            if ($full || $gone->getResponse()->getStatusCode() !== 410) { throw $gone; }
            return $this->catchUp(null, true);
        }
    }

    private function catchUp(?array $saved, bool $full): array
    {
        // Capture BEFORE scanning, then replay writes that raced with the scan.
        $cursor = $full ? $this->get('/api/changes')[DatasetField::CURSOR] : $saved[DatasetField::CURSOR];
        $datasets = $full ? $this->datasets() : $saved[DatasetField::ITEMS];
        $through = null;
        do {
            $query = ['after' => $cursor];
            if ($through !== null) { $query[DatasetField::THROUGH] = $through; }
            $page = $this->get('/api/changes', $query);
            foreach (array_unique(array_column($page[DatasetField::ITEMS], DatasetField::DATASET_KEY)) as $key) {
                $dataset = $this->dataset($key);
                if ($dataset === null) { unset($datasets[$key]); }
                else { $datasets[$key] = $dataset; }
            }
            if ($page[DatasetField::HAS_MORE] && $page[DatasetField::CURSOR] === $cursor) {
                throw new \UnexpectedValueException('Dataset feed pagination did not advance.');
            }
            $cursor = $page[DatasetField::CURSOR];
            $through = $page[DatasetField::THROUGH];
        } while ($page[DatasetField::HAS_MORE]);
        return [DatasetField::VERSION => 1, DatasetField::SOURCE => $this->source(),
            DatasetField::CURSOR => $cursor, DatasetField::ITEMS => $datasets];
    }

    /**
     * Stream an artifact to $destination, verifying the bytes against X-Artifact-Sha256.
     *
     * Harvest hashes the opened file before streaming it; anything else (truncation, a proxy, a
     * file swapped mid-publication) leaves no file at $destination, so a working folio is never
     * replaced by bytes the provider did not vouch for.
     *
     * @return int bytes written
     */
    public function download(string $url, string $destination): int
    {
        $url = str_starts_with($url, '/') ? $this->downloadUrl($url) : $url;
        $response = $this->http->request('GET', $url, [
            'headers' => $this->downloadHeaders($url), 'timeout' => 120, 'max_redirects' => 0, 'buffer' => false,
        ]);
        if (($status = $response->getStatusCode()) !== 200) {
            throw new \RuntimeException(sprintf('Artifact download failed with HTTP %d: %s', $status, $url));
        }
        $expected = strtolower($response->getHeaders()['x-artifact-sha256'][0] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/', $expected)) {
            throw new ArtifactChecksumException(sprintf('Artifact response carries no valid X-Artifact-Sha256: %s', $url));
        }

        $filesystem = new Filesystem();
        $temp = $destination.'.part';
        $filesystem->mkdir(\dirname($destination));
        $out = new \SplFileObject($temp, 'wb');
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            foreach ($this->http->stream($response) as $chunk) {
                $content = $chunk->getContent();
                hash_update($hash, $content);
                $bytes += $out->fwrite($content);
            }
            $out = null;
            $actual = hash_final($hash);
            if (!hash_equals($expected, $actual)) {
                throw new ArtifactChecksumException(sprintf('Artifact checksum mismatch for %s: expected %s, got %s.', $url, $expected, $actual));
            }
            $filesystem->rename($temp, $destination, true);
        } finally {
            $out = null;
            $filesystem->remove($temp);
        }
        return $bytes;
    }

    public function downloadUrl(string $url): string
    {
        if (!str_starts_with($url, '/api/datasets/') || str_contains($url, '://')) {
            throw new \UnexpectedValueException('Artifact URL is outside the configured dataset API.');
        }
        return $this->source().$url;
    }

    /** Credentials are only sent to validated same-origin dataset artifact URLs. */
    public function downloadHeaders(string $url): array
    {
        if (!str_starts_with($url, $this->source().'/api/datasets/')) {
            throw new \UnexpectedValueException('Artifact URL is outside the configured dataset API.');
        }
        return ['Authorization' => 'Bearer '.$this->token];
    }
}
