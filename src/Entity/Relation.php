<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\FolioBundle\Repository\RelationRepository;

#[ORM\Entity(repositoryClass: RelationRepository::class)]
#[ORM\Table(name: 'relation')]
#[ORM\Index(name: 'idx_relation_left', columns: ['left_core', 'left_id'])]
#[ORM\Index(name: 'idx_relation_right', columns: ['right_core', 'right_id'])]
class Relation
{
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    public string $id;

    #[ORM\ManyToOne(targetEntity: RelationType::class)]
    #[ORM\JoinColumn(name: 'relation_type_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public RelationType $type;

    #[ORM\Column(length: 80)] public string $leftCore;
    #[ORM\Column(length: 180)] public string $leftId;
    #[ORM\Column(length: 80)] public string $rightCore;
    #[ORM\Column(length: 180)] public string $rightId;

    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    public array $extras = [];

    public function __construct(RelationType $type, string $leftId, string $rightId)
    {
        $this->type = $type;
        $this->leftCore = $type->leftCore;
        $this->leftId = $leftId;
        $this->rightCore = $type->rightCore;
        $this->rightId = $rightId;
        $this->id = hash('xxh128', implode('|', [$type->id, $leftId, $rightId]));
    }
}
