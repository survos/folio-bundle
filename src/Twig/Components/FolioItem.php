<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Twig\Components;

use Survos\FolioBundle\Entity\Core;
use Survos\FolioBundle\Entity\Row;
use Survos\FolioBundle\Service\FolioService;
use Survos\FolioBundle\Service\RowTermsResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

/**
 * Lean, public-facing single-photo view — fortepan.hu's /photos/?id=X page (big image,
 * details alongside, download/share/bookmark, prev/next), NOT folio-bundle's own
 * templates/folio/detail.html.twig, which is a deliberately chrome-free, power-user
 * viewer (schema debug, AI chat, OCR/handwriting tooling) built for a different use case —
 * see that template's own `.navbar { display: none; }` rule. This component renders
 * inside whatever chrome the host app's page template provides.
 *
 * Minimal usage:
 *   <twig:folio:item provider="mus" dataset="cazma" coreCode="obj" localId="2157686" />
 *
 * Pointing prev/next at a host app's own chrome-preserving page (same placeholder-token
 * convention as PhotoGrid's rowUrlTemplate — only needed to override; left empty (the
 * default) prev/next link straight to folio-bundle's own chrome-free survos_folio_row_show,
 * generated directly with real values, no placeholder templating involved):
 *   <twig:folio:item provider="mus" dataset="cazma" coreCode="obj" localId="2157686"
 *       rowUrlTemplate="{{ path('tenant_photo_show', {tenantCode: 'cazma', localId: '__LOCAL_ID__'}) }}" />
 */
#[AsTwigComponent(name: 'folio:item', template: '@SurvosFolioBundle/components/FolioItem.html.twig')]
final class FolioItem
{
    public string $provider = '';
    public string $dataset = '';
    public string $coreCode = '';
    public string $localId = '';

    /**
     * Optional override — same tokens/purpose as PhotoGrid::$rowUrlTemplate. Left empty (the
     * default) means buildAdjacentUrl() generates survos_folio_row_show directly with real
     * values, no placeholder templating needed.
     */
    public string $rowUrlTemplate = '';

    /**
     * Base gallery URL (no query string) -- only used by the fortepanLayout branch, to build
     * tag/donor "click to refine" links (?tag=X / ?donor=X). Empty (the default) means those
     * fields render as plain text instead of links; the non-fortepan layout never uses this at
     * all (its tags link to survos_folio_term_show directly, unrelated to this).
     */
    public string $galleryUrl = '';

    /**
     * OpenSeadragon (via iiif-bundle's <twig:iiif:viewer>) vs. a plain <img> with simple
     * click-to-zoom. Defaults to true (unchanged behavior for existing host apps) -- OPAN's own
     * "Fortepan Method" ethic is deliberately anti-"traditional archive" chrome/bling (per
     * partner direction, 2026-07-16), so openfoto passes false here; a host app closer to a
     * traditional archive UX can keep the full IIIF viewer.
     */
    public bool $useIiifViewer = true;

    /**
     * Swaps the rendered markup for a structural port of fortepan.hu's own /photos/?id=X
     * detail page (image left, fixed 280px light sidebar right, Description/Year/Photo ID/
     * Donor/Tags fields in that order) instead of this component's own default folio-row
     * layout. Default false -- existing consumers are unaffected; openfoto opts in for its
     * dedicated photo detail page (2026-07-30).
     */
    public bool $fortepanLayout = false;

    private ?Row $resolvedRow = null;
    private bool $rowResolved = false;
    private ?array $adjacent = null;

    public function __construct(
        private readonly FolioService $folios,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
        private readonly RowTermsResolver $rowTermsResolver,
    ) {
    }

    public function mount(
        string $provider = '',
        string $dataset = '',
        string $coreCode = '',
        string $localId = '',
        ?string $rowUrlTemplate = null,
        bool $useIiifViewer = true,
        bool $fortepanLayout = false,
        string $galleryUrl = '',
    ): void {
        $this->provider = $provider;
        $this->dataset = $dataset;
        $this->coreCode = $coreCode;
        $this->localId = $localId;
        $this->useIiifViewer = $useIiifViewer;
        $this->rowUrlTemplate = $rowUrlTemplate ?? '';
        $this->fortepanLayout = $fortepanLayout;
        $this->galleryUrl = $galleryUrl;
    }

    #[ExposeInTemplate]
    public function getRow(): ?Row
    {
        if ($this->rowResolved) {
            return $this->resolvedRow;
        }
        $this->rowResolved = true;

        $folioCode = "{$this->provider}/{$this->dataset}";
        $ctx = $this->folios->context($folioCode);
        $core = $ctx->em->find(Core::class, Core::id($folioCode, $this->coreCode));
        if ($core === null) {
            return null;
        }

        return $this->resolvedRow = $ctx->em->find(Row::class, Row::id($core->id, $this->localId));
    }

    #[ExposeInTemplate]
    public function getShareUrl(): string
    {
        return $this->requestStack->getCurrentRequest()?->getUri() ?? '';
    }

    /**
     * Escape hatch to folio-bundle's own chrome-free power-user viewer (schema debug, AI chat,
     * OCR/handwriting, claims, relations) — everything this lean component deliberately doesn't
     * show. Null if the row can't be resolved (nothing sensible to link to).
     */
    #[ExposeInTemplate]
    public function getFocusModeUrl(): ?string
    {
        $row = $this->getRow();
        if ($row === null) {
            return null;
        }

        return $this->urlGenerator->generate('survos_folio_row_show', [
            'folioCode' => "$this->provider/$this->dataset",
            'coreCode' => $this->coreCode,
            'dtoType' => $row->dtoType ?: 'row',
            'localId' => $this->localId,
        ]);
    }

    /**
     * Tags/keywords, grouped by term set (same data detail.html.twig's "Terms" card shows) —
     * see RowTermsResolver for the field->termset binding.
     *
     * @return array<string,list<array{code:string,label:string,term:?\Survos\FolioBundle\Entity\Term}>>
     */
    #[ExposeInTemplate]
    public function getTerms(): array
    {
        $row = $this->getRow();
        if ($row === null) {
            return [];
        }

        $folioCode = "{$this->provider}/{$this->dataset}";

        return $this->rowTermsResolver->resolve($this->folios->context($folioCode)->em, $folioCode, $row->dtoData ?? [], $row->extras ?? []);
    }

    #[ExposeInTemplate]
    public function getPrevUrl(): ?string
    {
        return $this->buildAdjacentUrl($this->getAdjacent()['prev'] ?? null);
    }

    #[ExposeInTemplate]
    public function getNextUrl(): ?string
    {
        return $this->buildAdjacentUrl($this->getAdjacent()['next'] ?? null);
    }

    /**
     * Adapted from FolioController::adjacentRows() — same query shape, not shared code
     * (that method is private to the controller); a real "one folio row" abstraction
     * living in one place is the longer-term fix if a third caller needs this.
     *
     * @return array{prev: ?array{localId: string, dtoType: string}, next: ?array{localId: string, dtoType: string}}
     */
    private function getAdjacent(): array
    {
        if ($this->adjacent !== null) {
            return $this->adjacent;
        }

        $row = $this->getRow();
        if ($row === null) {
            return $this->adjacent = ['prev' => null, 'next' => null];
        }

        $conn = $this->folios->context("{$this->provider}/{$this->dataset}")->em->getConnection();

        // ORDER BY here MUST match FolioRowProvider's own grid ordering (year ascending, nulls
        // last, local_id as a tiebreaker) -- it used to order by bare `rowid` (raw insertion
        // order), completely unrelated to the year-sorted grid every photo is actually browsed
        // in. "Next" could jump backward in year (confirmed: 1945 -> 1928, 2026-07-31) because
        // rowid order and year order have nothing to do with each other.
        $data = $conn->fetchAssociative(
            "SELECT prev_id, prev_type, next_id, next_type FROM (
                SELECT local_id,
                       LAG(local_id)  OVER (ORDER BY (json_extract(dto_data, '\$.year') IS NULL), json_extract(dto_data, '\$.year') ASC, local_id ASC) AS prev_id,
                       LAG(dto_type)  OVER (ORDER BY (json_extract(dto_data, '\$.year') IS NULL), json_extract(dto_data, '\$.year') ASC, local_id ASC) AS prev_type,
                       LEAD(local_id) OVER (ORDER BY (json_extract(dto_data, '\$.year') IS NULL), json_extract(dto_data, '\$.year') ASC, local_id ASC) AS next_id,
                       LEAD(dto_type) OVER (ORDER BY (json_extract(dto_data, '\$.year') IS NULL), json_extract(dto_data, '\$.year') ASC, local_id ASC) AS next_type
                FROM item WHERE core_id = :core
            ) WHERE local_id = :current",
            ['core' => $row->core->id, 'current' => $this->localId],
        ) ?: [];

        $mk = static fn (?string $id, ?string $type): ?array => $id === null || $id === ''
            ? null
            : ['localId' => $id, 'dtoType' => $type ?: 'row'];

        return $this->adjacent = [
            'prev' => $mk($data['prev_id'] ?? null, $data['prev_type'] ?? null),
            'next' => $mk($data['next_id'] ?? null, $data['next_type'] ?? null),
        ];
    }

    private function buildAdjacentUrl(?array $target): ?string
    {
        if ($target === null) {
            return null;
        }

        if ($this->rowUrlTemplate === '') {
            return $this->urlGenerator->generate('survos_folio_row_show', [
                'folioCode' => "$this->provider/$this->dataset",
                'coreCode' => $this->coreCode,
                'dtoType' => $target['dtoType'],
                'localId' => $target['localId'],
            ]);
        }

        return str_replace(
            ['__CORE_CODE__', '__DTO_TYPE__', '__LOCAL_ID__'],
            [$this->coreCode, $target['dtoType'], $target['localId']],
            $this->rowUrlTemplate,
        );
    }
}
