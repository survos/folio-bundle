<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Twig;

use League\CommonMark\CommonMarkConverter;
use Survos\DataContracts\Vocabulary\Core;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFilter;
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

    private ?CommonMarkConverter $converter = null;

    /**
     * Encode a folio-search param map (sortBy, facet values, a pinned dtoType, …) into the url-safe
     * base64 token the slideshow `{filter}` route segment accepts — the server-side mirror of the
     * slideshow-link Stimulus controller. Empty map → '' (use the unfiltered slideshow route instead).
     *
     * @param array<string, scalar|array<scalar>> $params
     */
    #[AsTwigFunction('slideshow_filter')]
    public function slideshowFilter(array $params): string
    {
        if ($params === []) {
            return '';
        }

        return rtrim(strtr(base64_encode(http_build_query($params)), '+/', '-_'), '=');
    }

    public function __construct(
        private readonly ?UrlGeneratorInterface $urlGenerator = null,
        /** Host-app route that lists/searches datasets (e.g. zm's `app_search`); null disables provider links. */
        private readonly ?string $searchRoute = null,
        /** Query param the search route reads to pre-select a provider/aggregator facet. */
        private readonly string $searchProviderParam = 'dataset_aggregator',
    ) {}

    /**
     * Render markdown → HTML — for AI prose (observation, summaries) that uses paragraph breaks and
     * occasional emphasis. Tolerates legacy double-JSON-encoded claim values: ai:observationProse is
     * stored raw in folio extras as "..\n.." (wrapping quotes + literal backslash-n, not real
     * newlines), so decode that first or markdown would just print the literal \n. is_safe html —
     * CommonMark escapes the source content itself.
     */
    #[AsTwigFilter('markdown', isSafe: ['html'])]
    public function markdown(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        $trimmed = trim($text);
        if (\strlen($trimmed) >= 2 && $trimmed[0] === '"' && str_ends_with($trimmed, '"')) {
            $decoded = json_decode($trimmed);
            if (\is_string($decoded)) {
                $text = $decoded;
            }
        } elseif (str_contains($text, '\\n')) {
            // Unquoted but escaped (defensive): turn literal \r\n / \n / \t into real whitespace.
            $text = str_replace(['\\r\\n', '\\n', '\\t'], ["\n", "\n", "\t"], $text);
        }

        $this->converter ??= new CommonMarkConverter();

        return (string) $this->converter->convert($text);
    }

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
