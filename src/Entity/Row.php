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
use Survos\FolioBundle\Repository\RowRepository;
use Survos\FolioBundle\State\FolioRowProvider;
use Survos\IiifBundle\Service\IiifUrl;
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
class Row
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

    private ?string $resolvedThumbnailUrl = null;

    public function __construct(Core $core, string $localId)
    {
        $this->core    = $core;
        $this->localId = $localId;
        $this->id      = self::id($core->id, $localId);
        $this->claims  = new ArrayCollection();
    }

    public static function id(string $coreId, string $localId): string { return "$coreId:$localId"; }

    // Core ID format: "provider/dataset:coreCode" e.g. "dc/4168b346w:obj"
    public function getProvider(): string  { return explode('/', explode(':', $this->core->id, 2)[0], 2)[0]; }
    public function getDataset(): string   { return explode('/', explode(':', $this->core->id, 2)[0], 2)[1]; }
    public function getCoreCode(): string  { return explode(':', $this->core->id, 2)[1]; }

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

    /**
     * Route parameters consumed by api-grid link columns.
     *
     * @return array{provider: string, dataset: string, coreCode: string, dtoType: string, localId: string}
     */
    #[Groups(['row:read'])]
    public function getRp(): array
    {
        return [
            'provider' => $this->getProvider(),
            'dataset' => $this->getDataset(),
            'coreCode' => $this->getCoreCode(),
            'dtoType' => $this->dtoType ?: 'row',
            'localId' => $this->localId,
        ];
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
