<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Set;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * An app's sites (survos_folio.sites): hosts plus the folio sets they show. A site has no criteria
 * of its own — sets hold those — so its folios are the union of its sets' members. See
 * docs/folio-sets.md.
 */
final readonly class FolioSiteRegistry
{
    /** @param array<string, array{hosts: list<string>, sets: list<string>, restrict: bool}> $sites */
    public function __construct(
        #[Autowire('%survos_folio.sites%')]
        private array $sites,
        private FolioSetResolver $sets,
    ) {}

    /** @return array<string, array{hosts: list<string>, sets: list<string>, restrict: bool}> */
    public function all(): array
    {
        return $this->sites;
    }

    public function has(string $code): bool
    {
        return isset($this->sites[$code]);
    }

    /** @return array{hosts: list<string>, sets: list<string>, restrict: bool} */
    public function get(string $code): array
    {
        return $this->sites[$code] ?? throw new \InvalidArgumentException(sprintf('No folio site "%s". Defined: %s.', $code, implode(', ', array_keys($this->sites)) ?: 'none'));
    }

    /** The site serving this host, or null. Hosts compare case-insensitively and exactly. */
    public function forHost(string $host): ?string
    {
        $host = strtolower($host);
        foreach ($this->sites as $code => $site) {
            if (in_array($host, array_map('strtolower', $site['hosts']), true)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * The site's folios: every member of every set it shows, once each.
     *
     * @return array<string, array<string, mixed>> datasetKey → member
     */
    public function members(string $code): array
    {
        $members = [];
        foreach ($this->get($code)['sets'] as $set) {
            foreach ($this->sets->members($set) as $member) {
                $members[$member['datasetKey']] ??= $member;
            }
        }
        ksort($members);

        return $members;
    }

    public function contains(string $code, string $datasetKey): bool
    {
        return isset($this->members($code)[$datasetKey]);
    }

    /** A site whose sets add up to one folio is that folio's own site. */
    public function isSingle(string $code): bool
    {
        return count($this->members($code)) === 1;
    }
}
