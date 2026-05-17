<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FolioBundle\Repository\RowRepository;
use Survos\FolioBundle\State\FolioRowProvider;
use Symfony\Component\Serializer\Annotation\Groups;

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
            uriVariables: ['provider', 'dataset', 'coreCode'],
            provider: FolioRowProvider::class,
            name: self::API_ROWS,
        ),
    ],
    shortName: 'FolioRow',
)]
#[ApiFilter(SearchFilter::class, properties: ['dtoType' => 'exact', 'label' => 'partial'])]
class Row
{
    public const API_ROWS = 'folio_rows';

    #[ORM\Id]
    #[ORM\Column(length: 260)]
    #[Groups(['row:read'])]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Core::class)]
    #[ORM\JoinColumn(name: 'core_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public Core $core;

    #[ORM\Column(name: 'local_id', length: 180)]
    #[Groups(['row:read'])]
    public string $localId;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['row:read'])]
    public ?string $label = null;

    #[ORM\Column(name: 'dto_type', length: 255, nullable: true)]
    #[Groups(['row:read'])]
    public ?string $dtoType = null;

    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    #[Groups(['row:read'])]
    public array $dtoData = [];

    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    #[Groups(['row:read'])]
    public array $extras = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $raw = null;

    public function __construct(Core $core, string $localId)
    {
        $this->core    = $core;
        $this->localId = $localId;
        $this->id      = self::id($core->id, $localId);
    }

    public static function id(string $coreId, string $localId): string { return "$coreId:$localId"; }
}
