<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\FolioBundle\Repository\TermSetRepository;

#[ORM\Entity(repositoryClass: TermSetRepository::class)]
#[ORM\Table(name: 'term_set')]
#[ORM\UniqueConstraint(name: 'uniq_term_set_code', columns: ['folio_code', 'code'])]
class TermSet
{
    #[ORM\Id]
    #[ORM\Column(length: 180)]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Folio::class)]
    #[ORM\JoinColumn(name: 'folio_code', referencedColumnName: 'code', nullable: false, onDelete: 'CASCADE')]
    public Folio $folio;

    #[ORM\Column(length: 120)]
    public string $code;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $label = null;

    public function __construct(Folio $folio, string $code)
    {
        $this->folio = $folio;
        $this->code = $code;
        $this->id = "$folio->code:$code";
    }
}
