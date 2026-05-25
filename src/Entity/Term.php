<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\FolioBundle\Repository\TermRepository;

#[ORM\Entity(repositoryClass: TermRepository::class)]
#[ORM\Table(name: 'term')]
#[ORM\UniqueConstraint(name: 'uniq_term_code', columns: ['term_set_id', 'code'])]
#[ORM\UniqueConstraint(name: 'uniq_term_path', columns: ['term_set_id', 'path'])]
#[ORM\Index(name: 'idx_term_path', columns: ['term_set_id', 'path'])]
class Term
{
    #[ORM\Id]
    #[ORM\Column(length: 260, options: ['comment' => 'Composite: termSetId:code'])]
    public string $id;

    #[ORM\ManyToOne(targetEntity: TermSet::class)]
    #[ORM\JoinColumn(name: 'term_set_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public TermSet $termSet;

    #[ORM\Column(length: 180, options: ['comment' => 'Term code within the vocabulary'])]
    public string $code;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public ?self $parent = null;

    #[ORM\Column(length: 512, options: ['comment' => 'Materialized path for hierarchy'])]
    public string $path;

    #[ORM\Column(length: 500, nullable: true, options: ['comment' => 'Human-readable display label'])]
    public ?string $label = null;

    #[ORM\Column(type: Types::TEXT, nullable: true, options: ['comment' => 'Term description'])]
    public ?string $description = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Validation and constraint rules'])]
    public ?array $rules = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Arbitrary term metadata'])]
    public ?array $meta = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Extra non-canonical fields'])]
    public ?array $extras = null;

    #[ORM\Column(options: ['default' => true, 'comment' => 'Whether this term is active'])]
    public bool $enabled = true;

    #[ORM\Column(nullable: true, options: ['comment' => 'Sort order within parent'])]
    public ?int $sort = null;

    public function __construct(TermSet $termSet, string $code)
    {
        $this->termSet = $termSet;
        $this->code = $code;
        $this->path = $code;
        $this->id = "$termSet->id:$code";
    }
}
