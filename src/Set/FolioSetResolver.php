<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Set;

use Survos\DatasetBundle\Entity\Artifact;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\FolioBundle\Catalog\FolioCatalogClient;
use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves an app's folio sets (survos_folio.folio_sets). A set selects folios two ways, which may
 * be combined: by criteria over their metadata (`tags`, `tagsAll`, `provider`, `contentType`), so a
 * folio joins by gaining a tag in harvest, or by name (`folios`), for the one- or few-folio set.
 * Membership is derived and can always be rebuilt. See docs/folio-sets.md.
 *
 * Two sources answer the same criteria with the same {@see matches()}: the hub catalog
 * (`<folio_server>/folio/list.json`, or the Harvest dataset API), which is what a reading app sees, and the local dataset
 * registry, which is what the app that builds the folios sees. folio:sets:sync records the result;
 * members() reads it back.
 */
final class FolioSetResolver
{
    /** Criteria that select by metadata; `folios` selects by name, `minRows` only filters. */
    private const array SELECTIVE = ['tags', 'tagsAll', 'provider', 'contentType'];

    /** @var array<string, list<string>> folio file → content types, read once per process */
    private array $contentTypes = [];

    /** @param array<string, array{label: ?string, core: string, criteria: array<string, mixed>}> $sets */
    public function __construct(
        #[Autowire('%survos_folio.folio_sets%')]
        private readonly array $sets,
        #[Autowire('%kernel.project_dir%/var/folio-sets')]
        private readonly string $membershipDir,
        private readonly ?DatasetInfoRepository $datasets = null,
        private readonly ?FolioCatalogClient $catalog = null,
        private readonly ?FolioService $folios = null,
        /** auto: the hub catalog when folio_server or the dataset API is set, else the local registry */
        #[Autowire('%survos_folio.folio_sets_source%')]
        private readonly string $source = 'auto',
        #[Autowire('%survos_folio.folio_server%')]
        private readonly ?string $folioServer = null,
    ) {}

    /** @return array<string, array{label: ?string, core: string, criteria: array<string, mixed>}> */
    public function sets(): array
    {
        return $this->sets;
    }

    public function has(string $code): bool
    {
        return isset($this->sets[$code]);
    }

    public function source(): string
    {
        if ($this->source !== 'auto') {
            return $this->source;
        }

        // A consumer reads Harvest's API/feed, never its registry: the dataset API counts as a
        // catalog even with folio_server empty.
        return $this->catalog !== null && (($this->folioServer ?? '') !== '' || $this->catalog->usesDatasetApi()) ? 'catalog' : 'registry';
    }

    /**
     * Evaluate a set's criteria now. Throws when the source cannot answer at all, so a caller can
     * keep the last recorded membership instead of emptying the set.
     *
     * @return list<array{datasetKey: string, label: ?string, provider: string, tags: list<string>, rowCount: ?int, inCatalog: bool, downloadUrl: ?string, local: ?string, available: bool}>
     */
    public function resolve(string $code): array
    {
        $set = $this->sets[$code] ?? throw new \InvalidArgumentException(sprintf('No folio set "%s". Defined: %s.', $code, implode(', ', array_keys($this->sets)) ?: 'none'));
        $criteria = $set['criteria'];
        $named = array_values(array_unique(array_map('strval', $criteria['folios'] ?? [])));
        $selective = array_filter(self::SELECTIVE, static fn (string $k): bool => ($criteria[$k] ?? []) !== []) !== [];

        $members = [];
        foreach ($this->candidates($criteria) as $candidate) {
            $byName = in_array($candidate['datasetKey'], $named, true);
            if ($byName || ($selective && self::matches($criteria, $candidate))) {
                $members[$candidate['datasetKey']] = $candidate;
            }
        }
        // A named folio the source does not know is still a member: it may be on disk (built
        // here, or published under another route), and if not, sync reports it rather than the
        // set silently shrinking.
        foreach ($named as $datasetKey) {
            $members[$datasetKey] ??= $this->member($datasetKey, null, strtolower(explode('/', $datasetKey)[0]), [], null, false, null);
        }
        ksort($members);

        return array_values($members);
    }

    /**
     * The recorded membership from the last folio:sets:sync, or a live resolution if there is none.
     *
     * @return list<array<string, mixed>>
     */
    public function members(string $code): array
    {
        $recorded = $this->recorded($code);

        return $recorded !== null ? $recorded['members'] : $this->resolve($code);
    }

    /**
     * A set of one is the folio itself: its site renders that folio's home and search, not a
     * collection of one. Decided by the recorded membership, so a tag set becomes a collection on
     * the sync where a second folio gains the tag.
     */
    public function isSingle(string $code): bool
    {
        return count($this->members($code)) === 1;
    }

    /** @return array<string, mixed>|null the only member of a set of one */
    public function single(string $code): ?array
    {
        $members = $this->members($code);

        return count($members) === 1 ? $members[0] : null;
    }

    /** @return array{code: string, label: ?string, criteria: array<string, mixed>, resolvedAt: string, members: list<array<string, mixed>>}|null */
    public function recorded(string $code): ?array
    {
        $file = $this->membershipFile($code);
        if (!is_file($file)) {
            return null;
        }

        return json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }

    public function membershipFile(string $code): string
    {
        return rtrim($this->membershipDir, '/').'/'.$code.'.json';
    }

    /** The folio file for a dataset key if it is on disk here, else null. */
    public function localPath(string $datasetKey): ?string
    {
        if ($this->folios === null) {
            return null;
        }
        $path = $this->folios->path($datasetKey);

        return is_file($path) ? $path : null;
    }

    /** Whether a candidate meets every metadata criterion. Public and static so it is testable on plain arrays. */
    public static function matches(array $criteria, array $candidate): bool
    {
        $norm = static fn (array $values): array => array_map(static fn ($v): string => strtolower(trim((string) $v)), $values);
        $tags = $candidate['tags'];
        if (($criteria['tags'] ?? []) !== [] && array_intersect($norm($criteria['tags']), $tags) === []) {
            return false;
        }
        if (($criteria['tagsAll'] ?? []) !== [] && array_diff($norm($criteria['tagsAll']), $tags) !== []) {
            return false;
        }
        if (($criteria['provider'] ?? []) !== [] && !in_array($candidate['provider'], $norm($criteria['provider']), true)) {
            return false;
        }
        if (($criteria['contentType'] ?? []) !== [] && array_intersect($norm($criteria['contentType']), $candidate['contentTypes'] ?? []) === []) {
            return false;
        }

        return ($candidate['rowCount'] ?? 0) >= (int) ($criteria['minRows'] ?? 1);
    }

    /** @return iterable<array<string, mixed>> */
    private function candidates(array $criteria): iterable
    {
        if ($this->source() === 'catalog') {
            $entries = $this->catalog->all();
            if ($entries === [] && $this->catalog->isStale()) {
                throw new \RuntimeException(sprintf('The folio catalog %s is unreachable and nothing is cached.', $this->catalog->url()));
            }
            foreach ($entries as $entry) {
                // A translated variant (mus/enterreno + "en") is the same folio for membership;
                // folio:pull fetches the variants along with it.
                if ($entry->locale === null) {
                    yield $this->fromCatalog($entry);
                }
            }

            return;
        }

        if ($this->datasets === null) {
            throw new \RuntimeException('Folio sets resolve against the local dataset registry here, and it is not available. Set survos_folio.folio_server or enable survos_folio.dataset_api to resolve against the hub catalog instead.');
        }
        foreach ($this->datasets->findAll() as $info) {
            if (($candidate = $this->fromRegistry($info, $criteria)) !== null) {
                yield $candidate;
            }
        }
    }

    private function fromCatalog(FolioCatalogEntry $entry): array
    {
        return $this->member(
            $entry->datasetKey, $entry->title, strtolower($entry->provider), $entry->tags, $entry->rowCount, true, $entry->downloadUrl,
            $entry->contentType !== null ? [strtolower($entry->contentType)] : [],
        );
    }

    private function fromRegistry(DatasetInfo $info, array $criteria): ?array
    {
        $artifact = $info->artifact(Artifact::TYPE_FOLIO);
        if ($artifact === null) {
            return null;
        }
        $folio = $artifact->uri !== null && is_file($artifact->uri) ? $artifact->uri : null;

        return $this->member(
            $info->datasetKey, $info->label, strtolower($info->provider()), $info->getTags(), $artifact->rowCount, true, null,
            // Only worked out when a criterion needs it: it may mean opening the folio file.
            ($criteria['contentType'] ?? []) !== [] ? $this->contentTypes($artifact, $folio) : [],
            $folio,
        );
    }

    /** @param list<string> $tags @param list<string> $contentTypes */
    private function member(string $datasetKey, ?string $label, string $provider, array $tags, ?int $rowCount, bool $inCatalog, ?string $downloadUrl, array $contentTypes = [], ?string $local = null): array
    {
        $local ??= $this->localPath($datasetKey);

        return [
            'datasetKey' => $datasetKey,
            'label' => $label,
            'provider' => $provider,
            'tags' => $tags,
            'contentTypes' => $contentTypes,
            'rowCount' => $rowCount,
            'inCatalog' => $inCatalog,
            'downloadUrl' => $downloadUrl,
            'local' => $local,
            'available' => $local !== null,
        ];
    }

    /**
     * A folio's content types: the artifact's recorded contentType if the registry has one,
     * otherwise the DTO types in the folio's own schema_table.
     *
     * @return list<string>
     */
    private function contentTypes(Artifact $artifact, ?string $folio): array
    {
        if (is_string($artifact->metadata['contentType'] ?? null)) {
            return [strtolower($artifact->metadata['contentType'])];
        }
        if ($folio === null) {
            return [];
        }
        if (!isset($this->contentTypes[$folio])) {
            $pdo = new \Pdo\Sqlite('sqlite:'.$folio, options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY,
            ]);
            // schema_table records each DTO table's type; a multi-core folio has several
            // (cron-america: doc → newspaper, article → document).
            $this->contentTypes[$folio] = array_values(array_map('strtolower', $pdo->query(
                'SELECT DISTINCT dto_type FROM schema_table WHERE dto_type IS NOT NULL'
            )->fetchAll(\PDO::FETCH_COLUMN)));
        }

        return $this->contentTypes[$folio];
    }
}
