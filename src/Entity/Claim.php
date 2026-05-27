<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'claim')]
#[ORM\Index(name: 'idx_claim_predicate', columns: ['predicate'])]
#[ORM\Index(name: 'idx_claim_source', columns: ['source'])]
class Claim
{
    #[ORM\Id]
    #[ORM\Column(length: 64, options: ['comment' => 'Hash of item_id|predicate|source|value'])]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Row::class)]
    #[ORM\JoinColumn(name: 'item_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public Row $item;

    #[ORM\Column(length: 120, options: ['comment' => 'Field name this claim asserts (e.g. medium, description)'])]
    public string $predicate;

    #[ORM\Column(type: Types::TEXT, nullable: true, options: ['comment' => 'Asserted value (scalar as text, or JSON for complex)'])]
    public ?string $value = null;

    #[ORM\Column(length: 40, options: ['comment' => 'Who/what made this claim: ai, ocr, human, import'])]
    public string $source;

    #[ORM\Column(nullable: true, options: ['comment' => 'Confidence 0.0–1.0 (null = certain)'])]
    public ?float $confidence = null;

    #[ORM\Column(length: 120, nullable: true, options: ['comment' => 'Model or agent that produced this claim'])]
    public ?string $agent = null;

    #[ORM\Column(nullable: true, options: ['comment' => 'When this claim was made'])]
    public ?\DateTimeImmutable $claimedAt = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Extra provenance metadata'])]
    public ?array $meta = null;

    public function __construct(Row $item, string $predicate, string $source)
    {
        $this->item = $item;
        $this->predicate = $predicate;
        $this->source = $source;
        $this->id = hash('xxh128', implode('|', [$item->id, $predicate, $source, $this->value ?? '']));
    }
}
