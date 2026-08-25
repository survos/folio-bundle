<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\DataContracts\Util\ImageUrl;
use Survos\DataContracts\Util\ImageUrlVerdict;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Entity\RouteParametersInterface;
use Survos\FolioBundle\Repository\RowRepository;
use Survos\FolioBundle\State\FolioRowProvider;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Serializer\Attribute\Groups;

#[EntityMeta(icon: 'mdi:table-row', group: 'Folio', label: 'Folio Rows', adminBrowsable: false)]
#[ORM\Entity(repositoryClass: RowRepository::class)]
#[ORM\Table(name: 'item')]
#[ORM\UniqueConstraint(name: 'uniq_item_core_local', columns: ['core_id', 'local_id'])]
#[ORM\Index(name: 'idx_item_label', columns: ['label'])]
#[ApiResource(
    normalizationContext: ['groups' => ['row:read']],
    operations: [
        new GetCollection(
            uriTemplate: '/folios/{provider}/{dataset}/{coreCode}/rows',
            uriVariables: [
                'provider' => new Link(identifiers: ['provider']),
                'dataset' => new Link(identifiers: ['dataset']),
                'coreCode' => new Link(identifiers: ['coreCode']),
            ],
            provider: FolioRowProvider::class,
            name: self::API_ROWS,
            itemUriTemplate: '/folios/{provider}/{dataset}/{coreCode}/rows/{localId}',
            normalizationContext: ['groups' => ['row:read']],
        ),
        new Get(
            uriTemplate: '/folios/{provider}/{dataset}/{coreCode}/rows/{localId}',
            uriVariables: [
                'provider' => new Link(identifiers: ['provider']),
                'dataset' => new Link(identifiers: ['dataset']),
                'coreCode' => new Link(identifiers: ['coreCode']),
                'localId' => new Link(identifiers: ['localId']),
            ],
            provider: FolioRowProvider::class,
            normalizationContext: ['groups' => ['row:read']],
        ),
    ],
    shortName: 'FolioRow',
)]
#[ApiFilter(SearchFilter::class, properties: ['dtoType' => 'exact', 'label' => 'partial'])]
class Row implements RouteParametersInterface
{
    public const API_ROWS = 'folio_rows';

    #[ORM\Id]
    #[ORM\Column(length: 260, options: ['comment' => 'Composite: folioCode:coreCode:localId'])]
    #[Groups(['row:read'])]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Core::class)]
    #[ORM\JoinColumn(name: 'core_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public Core $core;

    #[ORM\Column(name: 'local_id', length: 180, options: ['comment' => 'Original ID from source data'])]
    #[Groups(['row:read'])]
    public string $localId;

    #[ORM\Column(length: 500, nullable: true, options: ['comment' => 'Display label'])]
    #[Groups(['row:read'])]
    public ?string $label = null;

    #[ORM\Column(name: 'dto_type', length: 255, nullable: true, options: ['comment' => 'DTO type identifier (e.g. drawing, photograph)'])]
    #[Groups(['row:read'])]
    public ?string $dtoType = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Canonical DTO-shaped JSON'])]
    #[Groups(['row:read'])]
    public ?array $dtoData = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Non-DTO source fields'])]
    #[Groups(['row:read'])]
    public ?array $extras = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Optional raw source copy'])]
    public ?array $raw = null;

    /** @var Collection<int, Claim> */
    #[ORM\OneToMany(targetEntity: Claim::class, mappedBy: 'item')]
    public Collection $claims;

    /** @var Collection<int, Page> Ordered viewable pages — the canonical imagery for this row. */
    #[ORM\OneToMany(targetEntity: Page::class, mappedBy: 'row')]
    #[ORM\OrderBy(['seq' => 'ASC'])]
    public Collection $pages;

    private ?string $resolvedThumbnailUrl = null;

    public function __construct(Core $core, string $localId)
    {
        $this->core    = $core;
        $this->localId = $localId;
        $this->id      = self::id($core->id, $localId);
        $this->claims  = new ArrayCollection();
        $this->pages   = new ArrayCollection();
    }

    public static function id(string $coreId, string $localId): string { return "$coreId:$localId"; }

    // Core ID format: "folioCode:coreCode" e.g. "dc/4168b346w:obj" -- folioCode itself is
    // "provider/dataset" ("dc/4168b346w"), but provider/dataset are never used separately for
    // routing (route params collapsed to one {folioCode} segment; see class docblock).
    public function getFolioCode(): string { return explode(':', $this->core->id, 2)[0]; }
    public function getCoreCode(): string  { return explode(':', $this->core->id, 2)[1]; }

    // Kept ONLY for the #[ApiResource] Link(identifiers: ['provider'/'dataset']) above -- that
    // API is its own separate, not-yet-done migration to a single folioCode segment (see
    // PhotoGrid's docblock), so IriConverter still needs these two split out by name.
    public function getProvider(): string { return explode('/', $this->getFolioCode(), 2)[0]; }
    public function getDataset(): string  { return explode('/', $this->getFolioCode(), 2)[1]; }

    #[Groups(['row:read'])]
    public function getCitationUrl(): ?string
    {
        return $this->canonicalDtoValue(ItemField::CITATION_URL);
    }

    /**
     * The image URL exactly as harvested, renderable or not. Only the debug/diagnostic path
     * should use this — everything user-facing wants {@see getThumbnailSource()}, which filters
     * out sources imgproxy cannot render.
     *
     * Imagery comes from PAGES ONLY. dto_data's iiifBase/largeImageUrl/thumbnailUrl are
     * housekeeping — provenance about where the row came from — and are deliberately NOT consulted
     * here, because the two disagree and the dto_data copy is the stale one.
     *
     * mus/rijk is the worked example: every one of its 10 object rows carries BOTH
     * page.url = s3://museado/orig/… (mediary's archived copy, written by enrich once the asset
     * reached a terminal place) AND dto_data.thumbnailUrl = https://fsn1.your-objectstorage.com/…
     * (the pre-S3-refactor public URL). Reading dto_data meant every thumbnail on a correctly built
     * folio still went out as a remote HTTP fetch, silently bypassing the S3 path the refactor
     * exists to establish — and doing it in a way that kept working, so nothing ever surfaced it.
     *
     * There is no dto_data fallback on purpose. A fallback would keep rendering the exact rows this
     * is meant to catch, so "no page" now means no thumbnail, and that is the intended signal:
     * a row with imagery and no page did not complete the dispatch → mediary → enrich path, and the
     * fix belongs in the pipeline, not in a reader working around it. Measured 2026-08-25 against
     * the pre-purge corpus, 26.4% of rows had a page (1,436,725 of 5,433,864) and 228 folios had
     * none at all — those go imageless until they are rebuilt through the gated workflow.
     */
    public function getRawThumbnailSource(): ?string
    {
        $page = $this->pages->first();

        // Page::$url is non-nullable and is defined as "what do I fetch" (its $sourceUrl holds the
        // provenance), so it is exactly the value imgproxy should be handed. Empty is treated as
        // absent so getImageVerdict() reports Empty rather than classifying a blank string.
        return $page instanceof Page && $page->url !== '' ? $page->url : null;
    }

    /**
     * Why this row's image is or isn't renderable, so templates can show an explicit
     * "unavailable" state instead of emitting an imgproxy request that is certain to fail
     * (survos-sites/musdig#40).
     */
    #[Groups(['row:read'])]
    public function getImageVerdict(): ImageUrlVerdict
    {
        $raw = $this->getRawThumbnailSource();

        return $raw === null ? ImageUrlVerdict::Empty : ImageUrl::classify($raw);
    }

    /**
     * The image URL to actually request. Null when the harvested value is a viewer config,
     * landing page, or document — handing those to imgproxy produces a guaranteed-broken
     * thumbnail, so callers get an explicit absence to render a placeholder for instead.
     */
    #[Groups(['row:read'])]
    public function getThumbnailSource(): ?string
    {
        $raw = $this->getRawThumbnailSource();

        return $raw !== null && ImageUrl::classify($raw)->isRenderable() ? $raw : null;
    }

    #[Groups(['row:read'])]
    public function getThumbnailUrl(): ?string
    {
        return $this->resolvedThumbnailUrl ?? $this->getThumbnailSource();
    }

    public function setResolvedThumbnailUrl(?string $resolvedThumbnailUrl): void
    {
        $this->resolvedThumbnailUrl = $resolvedThumbnailUrl;
    }

    /** Lazy, shared across every Row in the process -- Intl's name table doesn't change per-request. */
    private static ?array $countryCodesByName = null;

    /**
     * A handful of source-data country spellings that no longer match Symfony Intl's current
     * CLDR name table -- not a second full name list (see getCountryFlagCode()'s own doc), just
     * overrides for the cases where CLDR has since renamed a country and older aggregator data
     * still uses the previous English short name. "Turkey" -> CLDR's English name for TR is now
     * "Türkiye" (the 2022 ISO/UN short-name change), so the reverse lookup missed silently and
     * every Turkish photo showed no flag at all (2026-08-04).
     */
    private const LEGACY_COUNTRY_NAME_ALIASES = [
        'Turkey' => 'TR',
    ];

    /**
     * ISO 3166-1 alpha-2 code for `<span class="fi fi-{code}">` (flag-icons), or null when the
     * country isn't known/doesn't match. dto_data.country is free-text from the source
     * aggregator ("United States", "Switzerland", ...), not a code, so this reverse-looks-up
     * against Symfony Intl's own name table rather than shipping a second name->code list to
     * maintain. Exposed on the entity (not left to each template) so both the SSR-rendered page
     * and the client-fetched JSON pages (PhotoGrid's infinite scroll) get the same value from
     * one place.
     */
    #[Groups(['row:read'])]
    public function getCountryFlagCode(): ?string
    {
        $country = $this->canonicalDtoValue(ItemField::COUNTRY);
        if ($country === null) {
            return null;
        }

        self::$countryCodesByName ??= array_flip(Countries::getNames('en')) + self::LEGACY_COUNTRY_NAME_ALIASES;

        return self::$countryCodesByName[$country] ?? null;
    }

    /** @return array{folioCode: string, coreCode: string, dtoType: string, localId: string} */
    public function getUniqueIdentifiers(): array
    {
        return $this->getRp();
    }

    /**
     * Route parameters consumed by api-grid link columns and survos_folio_row_show.
     *
     * @return array<string, mixed>
     */
    #[Groups(['row:read'])]
    public function getRp(?array $addlParams = []): array
    {
        return array_merge([
            'folioCode' => $this->getFolioCode(),
            'coreCode' => $this->getCoreCode(),
            'dtoType' => $this->dtoType ?: 'row',
            'localId' => $this->localId,
        ], $addlParams ?? []);
    }

    public static function getClassnamePrefix(?string $class = null): string
    {
        return 'row';
    }

    /**
     * The row's heading, or null when the data genuinely has nothing to call it.
     *
     * One chain, read by both the detail template's <h1> and RowSchemaOrgBuilder's JSON-LD
     * `name`, so the page and the structured data can never disagree about what a row is
     * called. {@see \Survos\DataContracts\Dto\Item\BaseItemDto::label()} expresses the same
     * idea, but only sees the DTO — it can't consider Row::$label, and its final fallback is
     * the id, which is the thing this deliberately refuses to return.
     *
     * $label is skipped when it is just the localId repeated. FolioIngestService sets it that
     * way whenever the source gives it nothing better, and for mus/fortepan that is 219,586 of
     * 219,590 rows — the source ships a Hungarian description and no title, and the AI caption
     * that would become the label has run on ~0.4% of them. Falling through to the description
     * gives those pages a real heading instead of "<h1>1</h1>" without spending anything.
     *
     * Callers that must render something use `row.displayTitle ?: row.localId`; the id belongs
     * in the template's last resort, not in the value this returns.
     */
    public function displayTitle(): ?string
    {
        if (null !== $title = $this->canonicalDtoValue('title')) {
            return $title;
        }

        $label = trim((string) $this->label);
        if ('' !== $label && $label !== trim($this->localId)) {
            return $label;
        }

        // Prose, in the order BaseItemDto::mainText() prefers it, trimmed to a heading's worth.
        foreach (['description', 'sourceCaption', 'denseSummary'] as $field) {
            if (null !== $text = $this->canonicalDtoValue($field)) {
                return mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 80, '…');
            }
        }

        return null;
    }

    private function canonicalDtoValue(string $property): ?string
    {
        $value = $this->dtoData[$property] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
