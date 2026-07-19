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
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Entity\RouteParametersInterface;
use Survos\FolioBundle\Repository\RowRepository;
use Survos\FolioBundle\State\FolioRowProvider;
use Survos\IiifBundle\Service\IiifUrl;
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

    #[Groups(['row:read'])]
    public function getThumbnailSource(): ?string
    {
        $iiif = $this->canonicalDtoValue(ItemField::IIIF_BASE);
        if ($iiif !== null) {
            return IiifUrl::imageUrl($iiif);
        }

        return $this->canonicalDtoValue(ItemField::LARGE_IMAGE_URL)
            ?? $this->canonicalDtoValue(ItemField::THUMBNAIL_URL);
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

        self::$countryCodesByName ??= array_flip(Countries::getNames('en'));

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

    private function canonicalDtoValue(string $property): ?string
    {
        $value = $this->dtoData[$property] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
