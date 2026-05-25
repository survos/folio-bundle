<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'docs')]
#[ORM\Index(columns: ['type'])]
#[ORM\Index(columns: ['audience'])]
class Doc
{
    #[ORM\Id]
    #[ORM\Column(length: 120, options: ['comment' => 'Document slug (e.g. overview, schema, query-guide)'])]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Folio::class)]
    #[ORM\JoinColumn(name: 'folio_code', referencedColumnName: 'code', nullable: false, onDelete: 'CASCADE')]
    public Folio $folio;

    #[ORM\Column(length: 255, options: ['comment' => 'Document title'])]
    public string $title;

    #[ORM\Column(length: 24, options: ['comment' => 'Content type: md, json, html, text'])]
    public string $type = 'md';

    #[ORM\Column(length: 40, nullable: true, options: ['comment' => 'Target audience: human, developer, machine'])]
    public ?string $audience = null;

    #[ORM\Column(type: Types::TEXT, options: ['comment' => 'Document content'])]
    public string $body = '';

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Generation metadata'])]
    public ?array $metadata = null;

    #[ORM\Column(options: ['default' => 0, 'comment' => 'Display order'])]
    public int $position = 0;

    #[ORM\Column(name: 'generated_at', nullable: true, options: ['comment' => 'When this doc was last generated'])]
    public ?\DateTimeImmutable $generatedAt = null;

    public function __construct(Folio $folio, string $id, string $title)
    {
        $this->folio = $folio;
        $this->id = $id;
        $this->title = $title;
    }
}
