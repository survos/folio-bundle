<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Set;

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
 * Membership comes from a published catalog. Registry and pipeline decisions belong to its producer.
 */
final class FolioSetResolver
{
    /** Criteria that select by metadata; `folios` selects by name, `minRows` only filters. */
    private const array SELECTIVE = ['tags', 'tagsAll', 'provider', 'contentType'];

    /** @param array<string, array{label: ?string, core: string, criteria: array<string, mixed>}> $sets */
    public function __construct(
        #[Autowire('%survos_folio.folio_sets%')]
        private readonly array $sets,
        #[Autowire('%kernel.project_dir%/var/folio-sets')]
        private readonly string $membershipDir,
        private readonly ?FolioCatalogClient $catalog = null,
        private readonly ?FolioService $folios = null,
        /** auto: the hub catalog when folio_server or the dataset API is set, otherwise a configured catalog is required */
        #[Autowire('%survos_folio.folio_sets_source%')]
        private readonly string $source = 'auto',
        #[Autowire('%survos_folio.folio_server%')]
        private readonly ?string $folioServer = null,
        private readonly ?RecordedMembershipProvider $recordedMembership = null,
    ) {
        if (!in_array($source, ['auto', 'catalog'], true)) {
            throw new \InvalidArgumentException('Folio sets require a published catalog; registry selection is removed.');
        }
    }

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
        return 'catalog';
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
        $selective = [] !== array_filter(self::SELECTIVE, static fn (string $k): bool => ($criteria[$k] ?? []) !== []);

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
        if (null !== $this->recordedMembership) {
            if (!$this->has($code)) {
                throw new \InvalidArgumentException('Unknown folio set: '.$code);
            }

            return $this->recordedMembership->members($code);
        }
        $recorded = $this->recorded($code);

        return null !== $recorded ? $recorded['members'] : $this->resolve($code);
    }

    /**
     * A set of one is the folio itself: its site renders that folio's home and search, not a
     * collection of one. Decided by the recorded membership, so a tag set becomes a collection on
     * the sync where a second folio gains the tag.
     */
    public function isSingle(string $code): bool
    {
        return 1 === count($this->members($code));
    }

    /** @return array<string, mixed>|null the only member of a set of one */
    public function single(string $code): ?array
    {
        $members = $this->members($code);

        return 1 === count($members) ? $members[0] : null;
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
        if (null === $this->folios) {
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
        if (($criteria['tags'] ?? []) !== [] && [] === array_intersect($norm($criteria['tags']), $tags)) {
            return false;
        }
        if (($criteria['tagsAll'] ?? []) !== [] && [] !== array_diff($norm($criteria['tagsAll']), $tags)) {
            return false;
        }
        if (($criteria['provider'] ?? []) !== [] && !in_array($candidate['provider'], $norm($criteria['provider']), true)) {
            return false;
        }
        if (($criteria['contentType'] ?? []) !== [] && [] === array_intersect($norm($criteria['contentType']), $candidate['contentTypes'] ?? [])) {
            return false;
        }

        return ($candidate['rowCount'] ?? 0) >= (int) ($criteria['minRows'] ?? 1);
    }

    /** @return iterable<array<string, mixed>> */
    private function candidates(array $criteria): iterable
    {
        if (null === $this->catalog) {
            throw new \RuntimeException('Configure a folio catalog to resolve set membership.');
        }

        $entries = $this->catalog->all();
        if ([] === $entries && $this->catalog->isStale()) {
            throw new \RuntimeException(sprintf('The folio catalog %s is unreachable and nothing is cached.', $this->catalog->url()));
        }
        foreach ($entries as $entry) {
            // A translated variant (mus/enterreno + "en") is the same folio for membership;
            // folio:pull fetches the variants along with it.
            if (null === $entry->locale) {
                yield $this->fromCatalog($entry);
            }
        }
    }

    private function fromCatalog(FolioCatalogEntry $entry): array
    {
        return $this->member(
            $entry->datasetKey, $entry->title, strtolower($entry->provider), $entry->tags, $entry->rowCount, true, $entry->downloadUrl,
            null !== $entry->contentType ? [strtolower($entry->contentType)] : [],
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
            'available' => null !== $local,
        ];
    }
}
