<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Survos\FolioBundle\Repository\LinkTypeRepository;

#[ORM\Entity(repositoryClass: LinkTypeRepository::class)]
#[ORM\Table(name: 'link_type')]
#[ORM\UniqueConstraint(name: 'uniq_link_type', columns: ['folio_code', 'left_core', 'code', 'right_core'])]
class LinkType
{
    #[ORM\Id]
    #[ORM\Column(length: 260, options: ['comment' => 'Composite: folioCode:leftCore:code:rightCore'])]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Folio::class, inversedBy: 'linkTypes')]
    #[ORM\JoinColumn(name: 'folio_code', referencedColumnName: 'code', nullable: false, onDelete: 'CASCADE')]
    public Folio $folio;

    #[ORM\Column(length: 80, options: ['comment' => 'Source core code'])]
    public string $leftCore;

    #[ORM\Column(length: 120, options: ['comment' => 'Link type code'])]
    public string $code;

    #[ORM\Column(length: 80, options: ['comment' => 'Target core code'])]
    public string $rightCore;

    #[ORM\Column(length: 120, nullable: true, options: ['comment' => 'Inverse link code'])]
    public ?string $reverseCode = null;

    #[ORM\Column(length: 255, nullable: true, options: ['comment' => 'Human-readable label'])]
    public ?string $label = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $reverseLabel = null;

    /** @var Collection<int, Link> */
    #[ORM\OneToMany(targetEntity: Link::class, mappedBy: 'type', fetch: 'EXTRA_LAZY')]
    public Collection $links;

    public int $linkCount { get => $this->links->count(); }

    public function __construct(Folio $folio, string $leftCore, string $code, string $rightCore)
    {
        $this->folio = $folio;
        $this->leftCore = $leftCore;
        $this->code = $code;
        $this->rightCore = $rightCore;
        $this->id = implode(':', [$folio->code, $leftCore, $code, $rightCore]);
        $this->links = new ArrayCollection();
    }
}
