<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Survos\FolioBundle\Repository\FolioRepository;

#[ORM\Entity(repositoryClass: FolioRepository::class)]
#[ORM\Table(name: 'folio')]
class Folio
{
    #[ORM\Id]
    #[ORM\Column(length: 120, options: ['comment' => 'Unique folio identifier, matches dataset key'])]
    public string $code;

    #[ORM\Column(length: 255, nullable: true, options: ['comment' => 'Human-readable display name'])]
    public ?string $label = null;

    #[ORM\Column(length: 180, nullable: true, options: ['comment' => 'Dataset key from data-bundle (e.g. mus/cleveland)'])]
    public ?string $datasetKey = null;

    #[ORM\Column(options: ['default' => 0, 'comment' => 'Total rows across all cores'])]
    public int $rowCount = 0;

    /** @var Collection<int, LinkType> */
    #[ORM\OneToMany(targetEntity: LinkType::class, mappedBy: 'folio', fetch: 'EXTRA_LAZY')]
    public Collection $linkTypes;

    public function __construct(string $code)
    {
        $this->code = $code;
        $this->linkTypes = new ArrayCollection();
    }
}
