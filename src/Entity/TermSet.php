<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\FolioBundle\Repository\TermSetRepository;

#[ORM\Entity(repositoryClass: TermSetRepository::class)]
#[ORM\Table(name: 'term_set')]
#[ORM\UniqueConstraint(name: 'uniq_term_set_code', columns: ['folio_code', 'code'])]
class TermSet
{
    #[ORM\Id]
    #[ORM\Column(length: 180, options: ['comment' => 'Composite: folioCode:code'])]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Folio::class)]
    #[ORM\JoinColumn(name: 'folio_code', referencedColumnName: 'code', nullable: false, onDelete: 'CASCADE')]
    public Folio $folio;

    #[ORM\Column(length: 120, options: ['comment' => 'Vocabulary code within the folio'])]
    public string $code;

    #[ORM\Column(length: 255, nullable: true, options: ['comment' => 'Human-readable display name'])]
    public ?string $label = null;

    #[ORM\Column(type: Types::TEXT, nullable: true, options: ['comment' => 'Vocabulary description'])]
    public ?string $description = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Validation and constraint rules'])]
    public ?array $rules = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Arbitrary vocabulary metadata'])]
    public ?array $meta = null;

    #[ORM\Column(options: ['default' => true, 'comment' => 'Whether this vocabulary is active'])]
    public bool $enabled = true;

    public function __construct(Folio $folio, string $code)
    {
        $this->folio = $folio;
        $this->code = $code;
        $this->id = "$folio->code:$code";
    }
}
