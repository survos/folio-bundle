<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Catalog;

use Psr\Log\LoggerInterface;
use Survos\FolioBundle\Set\FolioSetResolver;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The one client for the hub's folio catalog.
 *
 * zm publishes which folios exist and what they are. Reading that had been written three times —
 * ink's FolioCatalog (/folio/list.json), fotostory's FolioCatalogClient (/api/dataset_infos, moved
 * there in 2026-07 after list.json answered 0 for a provider with 17 built folios), and mastheads'
 * InkCatalog (ink's own /folios.json) — three endpoints, three cache policies, three opinions on
 * what a missing field means. Consumers ask this instead.
 *
 * Two behaviours are deliberate, both inherited from ink's version because it got them right:
 *
 *  - An outage is not an empty catalog. A failed fetch keeps the last good payload and marks the
 *    result stale; callers that would otherwise "resolve" a folio set to nothing, and unpublish a
 *    site's entire contents on a blip, can check {@see isStale()} first.
 *  - The response shape is tolerated, not trusted. Deployments serve both a bare array and
 *    {"folios": [...]} today; an entry missing its datasetKey is skipped, not faked.
 *
 * Transport is an implementation detail on purpose. It reads /folio/list.json, which is the only
 * endpoint carrying the archive fields folio:pull needs (downloadUrl, checksum, compressed) and
 * now also carries tags. When that becomes a proper API Platform resource — self-documenting,
 * paginated, filterable — this class changes and its callers do not.
 */
final class FolioCatalogClient
{
    /** @var list<FolioCatalogEntry>|null */
    private ?array $entries = null;

    private bool $stale = false;

    private ?int $fetchedAt = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $server,
        private readonly string $cacheFile,
        private readonly int $ttl = 300,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?Filesystem $filesystem = null,
    ) {
    }

    public function url(): string
    {
        return rtrim($this->server, '/') . '/folio/list.json';
    }

    /** Whether the entries currently held came from cache because the hub could not be reached. */
    public function isStale(): bool
    {
        $this->all();

        return $this->stale;
    }

    /** Unix time of the payload in hand, cached or fresh; null when there has never been one. */
    public function fetchedAt(): ?int
    {
        $this->all();

        return $this->fetchedAt;
    }

    /**
     * Every published folio.
     *
     * @return list<FolioCatalogEntry>
     */
    public function all(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $saved = $this->readCache();
        $fresh = $saved !== null && (time() - (int) ($saved['fetchedAt'] ?? 0)) < $this->ttl;

        if (!$fresh) {
            try {
                $payload = $this->http->request('GET', $this->url(), [
                    // A catalog read sits in front of a page render; a hung hub must not hang the
                    // site when a cached answer is right there.
                    'timeout' => 5,
                    'max_duration' => 10,
                ])->toArray();

                $saved = ['source' => $this->url(), 'fetchedAt' => time(), 'folios' => self::rows($payload)];
                $this->writeCache($saved);
            } catch (\Throwable $e) {
                $this->stale = true;
                $this->logger?->warning('Folio catalog unavailable; keeping the last recorded metadata', [
                    'url' => $this->url(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->fetchedAt = $saved !== null ? (int) ($saved['fetchedAt'] ?? 0) ?: null : null;

        $entries = [];
        foreach (is_array($saved['folios'] ?? null) ? $saved['folios'] : [] as $row) {
            if (is_array($row) && ($entry = FolioCatalogEntry::fromArray($row)) !== null) {
                $entries[] = $entry;
            }
        }

        return $this->entries = $entries;
    }

    public function find(string $datasetKey): ?FolioCatalogEntry
    {
        foreach ($this->all() as $entry) {
            if ($entry->datasetKey === $datasetKey) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Entries matching folio-set criteria — `tags`, `tagsAll`, `provider`, `contentType`, `minRows`.
     *
     * Evaluated by {@see FolioSetResolver::matches()}, the same function that resolves a set
     * against the local registry, so a set selects the same folios whichever side answers.
     *
     * @param array<string, mixed> $criteria
     *
     * @return list<FolioCatalogEntry>
     */
    public function matching(array $criteria): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (FolioCatalogEntry $e): bool => FolioSetResolver::matches($criteria, [
                'datasetKey' => $e->datasetKey,
                'provider' => $e->provider,
                'tags' => $e->tags,
                'contentTypes' => $e->contentType !== null ? [strtolower($e->contentType)] : [],
                'rowCount' => $e->rowCount,
            ]),
        ));
    }

    /**
     * Both shapes seen in production: a bare array of folios, and {"folios": [...]}.
     *
     * @param array<mixed> $payload
     *
     * @return list<mixed>
     */
    private static function rows(array $payload): array
    {
        if (is_array($payload['folios'] ?? null)) {
            return array_values($payload['folios']);
        }

        return array_is_list($payload) ? $payload : [];
    }

    /** @return array<string, mixed>|null */
    private function readCache(): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }

        try {
            $saved = json_decode((string) file_get_contents($this->cacheFile), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger?->warning('Cannot read the folio catalog cache', ['error' => $e->getMessage()]);

            return null;
        }

        // A cache written against a different hub is not this hub's catalog.
        if (!is_array($saved) || ($saved['source'] ?? null) !== $this->url() || !is_array($saved['folios'] ?? null)) {
            return null;
        }

        return $saved;
    }

    /** @param array<string, mixed> $saved */
    private function writeCache(array $saved): void
    {
        try {
            ($this->filesystem ?? new Filesystem())->dumpFile($this->cacheFile, json_encode($saved, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            // An unwritable cache dir costs a fetch per request, which is worth a warning and
            // nothing more — the catalog itself still answered.
            $this->logger?->warning('Cannot write the folio catalog cache', ['error' => $e->getMessage()]);
        }
    }
}
