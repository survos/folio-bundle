<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Twig\Components;

use Survos\FolioBundle\Entity\Row;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

/**
 * Justified-grid, infinite-scroll photo browser for one core of one folio — the
 * fortepan.hu-style "browse everything" view, backed by Row's own #[ApiResource]
 * (Survos\FolioBundle\State\FolioRowProvider) rather than a new endpoint.
 *
 * Minimal usage:
 *   <twig:folio:photo-grid provider="mus" dataset="cazma" coreCode="obj" />
 *
 * With a date-range filter (see FolioRowProvider for what's actually implemented —
 * filters are hand-wired there, not automatic via #[ApiFilter]):
 *   <twig:folio:photo-grid provider="mus" dataset="cazma" coreCode="obj" :filters="{cfrom: '2025-12-07'}" />
 *
 * The route this targets (Row::API_ROWS = 'folio_rows') is still 2-segment
 * ({provider}/{dataset}/{coreCode}) — folio-bundle is mid-migration toward a single
 * unique dataset/slug segment (survos-sites/scanseum#12/#18), but that hasn't reached
 * Row's #[ApiResource] operations yet. This component targets what's live today;
 * update it alongside that migration when it does.
 *
 * First page is rendered server-side (getItems(), via $httpClient — optional, nullable
 * so the component degrades to an empty shell without symfony/http-client configured
 * for internal requests); the Stimulus controller (photo-grid, see assets/package.json)
 * takes over from page 2 onward via a client-side fetch against the same endpoint.
 */
#[AsTwigComponent(name: 'folio:photo-grid', template: '@SurvosFolioBundle/components/PhotoGrid.html.twig')]
final class PhotoGrid
{
    public string $provider = '';
    public string $dataset = '';
    public string $coreCode = '';

    /** @var array<string, scalar> extra query params merged onto the endpoint (e.g. cfrom/cto date-range) */
    public array $filters = [];

    public int $itemsPerPage = 50;

    /** Override the computed endpoint entirely (e.g. to point at a different app's folio API) */
    public ?string $endpoint = null;

    private ?array $items = null;

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ?HttpClientInterface $httpClient = null,
    ) {
    }

    /**
     * Explicit params, not a no-arg mount() — a UX Twig Component's mount(), when
     * defined, receives ONLY the props listed as its own parameters; anything not
     * listed here is silently left at its property default instead of the value the
     * tag attribute passed in. (Components with no derived default, like iiif-bundle's
     * IiifViewer, skip mount() entirely and get plain reflection-based hydration for
     * every public property instead.)
     */
    public function mount(
        string $provider = '',
        string $dataset = '',
        string $coreCode = '',
        array $filters = [],
        int $itemsPerPage = 50,
        ?string $endpoint = null,
    ): void {
        $this->provider = $provider;
        $this->dataset = $dataset;
        $this->coreCode = $coreCode;
        $this->filters = $filters;
        $this->itemsPerPage = $itemsPerPage;
        $this->endpoint = $endpoint ?? $this->urlGenerator->generate(Row::API_ROWS, [
            'provider' => $provider,
            'dataset' => $dataset,
            'coreCode' => $coreCode,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * First page of items, fetched server-side against the same endpoint the
     * Stimulus controller continues from. Same Hydra unwrapping tabler-bundle's
     * DataAwareTrait::fetchFromEndpoint() does (survos/mono#19) — folio-bundle
     * doesn't depend on tabler-bundle, so this is a small intentional duplicate
     * rather than a new cross-bundle dependency; worth converging into a shared
     * kit-bundle trait later if a third consumer needs it too.
     *
     * @return list<array<string, mixed>>
     */
    #[ExposeInTemplate]
    public function getItems(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        if ($this->httpClient === null || $this->endpoint === null) {
            return $this->items = [];
        }

        $query = array_merge($this->filters, ['page' => 1, 'itemsPerPage' => $this->itemsPerPage]);
        $data = $this->httpClient->request('GET', $this->endpoint, ['query' => $query])->toArray(false);

        return $this->items = $data['member'] ?? $data['hydra:member'] ?? [];
    }
}
