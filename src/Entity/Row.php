<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\FolioBundle\Repository\RowRepository;

#[ORM\Entity(repositoryClass: RowRepository::class)]
#[ORM\Table(name: 'item')]
#[ORM\UniqueConstraint(name: 'uniq_item_core_local', columns: ['core_id', 'local_id'])]
#[ORM\Index(name: 'idx_item_label', columns: ['label'])]
class Row
{
    #[ORM\Id]
    #[ORM\Column(length: 260)]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Core::class)]
    #[ORM\JoinColumn(name: 'core_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public Core $core;

    #[ORM\Column(name: 'local_id', length: 180)]
    public string $localId;

    #[ORM\Column(length: 500, nullable: true)]
    public ?string $label = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $dtoClass = null;

    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    public array $dtoData = [];

    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    public array $extras = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $raw = null;

    public function __construct(Core $core, string $localId)
    {
        $this->core = $core;
        $this->localId = $localId;
        $this->id = self::id($core->id, $localId);
    }

    public static function id(string $coreId, string $localId): string { return "$coreId:$localId"; }
}
