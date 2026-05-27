<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\FolioBundle\Repository\LinkRepository;

#[ORM\Entity(repositoryClass: LinkRepository::class)]
#[ORM\Table(name: 'link')]
#[ORM\Index(name: 'idx_link_left', columns: ['left_core', 'left_id'])]
#[ORM\Index(name: 'idx_link_right', columns: ['right_core', 'right_id'])]
class Link
{
    #[ORM\Id]
    #[ORM\Column(length: 32, options: ['comment' => 'xxh128 hash of type|leftId|rightId'])]
    public string $id;

    #[ORM\ManyToOne(targetEntity: LinkType::class, inversedBy: 'links')]
    #[ORM\JoinColumn(name: 'link_type_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public LinkType $type;

    #[ORM\Column(length: 80, options: ['comment' => 'Source core code'])]
    public string $leftCore;

    #[ORM\Column(length: 180, options: ['comment' => 'Source row local_id'])]
    public string $leftId;

    #[ORM\Column(length: 80, options: ['comment' => 'Target core code'])]
    public string $rightCore;

    #[ORM\Column(length: 180, options: ['comment' => 'Target row local_id'])]
    public string $rightId;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Extra link metadata'])]
    public ?array $extras = null;

    public function __construct(LinkType $type, string $leftId, string $rightId)
    {
        $this->type = $type;
        $this->leftCore = $type->leftCore;
        $this->leftId = $leftId;
        $this->rightCore = $type->rightCore;
        $this->rightId = $rightId;
        $this->id = hash('xxh128', implode('|', [$type->id, $leftId, $rightId]));
    }
}
