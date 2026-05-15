<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\FolioBundle\Repository\RelationTypeRepository;

#[ORM\Entity(repositoryClass: RelationTypeRepository::class)]
#[ORM\Table(name: 'relation_type')]
#[ORM\UniqueConstraint(name: 'uniq_relation_type', columns: ['folio_code', 'left_core', 'code', 'right_core'])]
class RelationType
{
    #[ORM\Id]
    #[ORM\Column(length: 260)]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Folio::class)]
    #[ORM\JoinColumn(name: 'folio_code', referencedColumnName: 'code', nullable: false, onDelete: 'CASCADE')]
    public Folio $folio;

    #[ORM\Column(length: 80)]
    public string $leftCore;

    #[ORM\Column(length: 120)]
    public string $code;

    #[ORM\Column(length: 80)]
    public string $rightCore;

    #[ORM\Column(length: 120, nullable: true)]
    public ?string $reverseCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $label = null;

    public function __construct(Folio $folio, string $leftCore, string $code, string $rightCore)
    {
        $this->folio = $folio;
        $this->leftCore = $leftCore;
        $this->code = $code;
        $this->rightCore = $rightCore;
        $this->id = implode(':', [$folio->code, $leftCore, $code, $rightCore]);
    }
}
