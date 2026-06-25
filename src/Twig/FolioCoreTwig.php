<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Twig;

use Survos\DataContracts\Vocabulary\Core;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig helpers for folio cores. `core_icon('obj')` → a Tabler icon name for the canonical Core
 * codes, so templates don't each carry their own code→icon map. Pass the result to ux_icon().
 */
final class FolioCoreTwig
{
    /** Canonical Core code → Tabler icon name. */
    private const ICONS = [
        Core::OBJECT       => 'tabler:box',
        Core::DOCUMENT     => 'tabler:file-text',
        Core::IMAGE        => 'tabler:photo',
        Core::COLLECTION   => 'tabler:folders',
        Core::SERIES       => 'tabler:stack-2',
        Core::PERSON       => 'tabler:user',
        Core::PLACE        => 'tabler:map-pin',
        Core::ORGANISATION => 'tabler:building',
        Core::EVENT        => 'tabler:calendar-event',
    ];

    public function __construct(
        private readonly ?UrlGeneratorInterface $urlGenerator = null,
        /** Host-app route that lists/searches datasets (e.g. zm's `app_search`); null disables provider links. */
        private readonly ?string $searchRoute = null,
        /** Query param the search route reads to pre-select a provider/aggregator facet. */
        private readonly string $searchProviderParam = 'dataset_aggregator',
    ) {}

    #[AsTwigFunction('core_icon')]
    public function coreIcon(string $coreCode): string
    {
        return self::ICONS[$coreCode] ?? 'tabler:database';
    }

    /**
     * URL to the host site's dataset search with this provider's facet pre-selected, or null when no
     * search route is configured (so the breadcrumb falls back to plain text). Keeps the shared
     * bundle template free of any app-specific route name.
     */
    #[AsTwigFunction('folio_provider_url')]
    public function providerUrl(string $provider): ?string
    {
        if ($this->searchRoute === null || $this->urlGenerator === null) {
            return null;
        }

        try {
            return $this->urlGenerator->generate($this->searchRoute, [$this->searchProviderParam => $provider]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
