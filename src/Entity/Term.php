<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\FolioBundle\Repository\TermRepository;

#[ORM\Entity(repositoryClass: TermRepository::class)]
#[ORM\Table(name: 'term')]
#[ORM\UniqueConstraint(name: 'uniq_term_code', columns: ['term_set_id', 'code'])]
class Term
{
    #[ORM\Id]
    #[ORM\Column(length: 260)]
    public string $id;

    #[ORM\ManyToOne(targetEntity: TermSet::class)]
    #[ORM\JoinColumn(name: 'term_set_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public TermSet $termSet;

    #[ORM\Column(length: 180)]
    public string $code;

    #[ORM\Column(length: 500, nullable: true)]
    public ?string $label = null;

    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    public array $extras = [];

    public function __construct(TermSet $termSet, string $code)
    {
        $this->termSet = $termSet;
        $this->code = $code;
        $this->id = "$termSet->id:$code";
    }
}
