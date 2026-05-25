<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'schema_table')]
#[ORM\Index(columns: ['core_code'])]
#[ORM\Index(columns: ['dto_type'])]
class SchemaTable
{
    #[ORM\Id]
    #[ORM\Column(length: 260, options: ['comment' => 'Composite: folioCode:tableName'])]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Folio::class)]
    #[ORM\JoinColumn(name: 'folio_code', referencedColumnName: 'code', nullable: false, onDelete: 'CASCADE')]
    public Folio $folio;

    #[ORM\Column(length: 80, options: ['comment' => 'Core this table describes'])]
    public string $coreCode;

    #[ORM\Column(length: 180, options: ['comment' => 'Table/view name (e.g. dto_drawing)'])]
    public string $name;

    #[ORM\Column(length: 32, options: ['comment' => 'Table kind: core or dto'])]
    public string $kind;

    #[ORM\Column(length: 255, nullable: true, options: ['comment' => 'DTO type identifier'])]
    public ?string $dtoType = null;

    #[ORM\Column(length: 255, nullable: true, options: ['comment' => 'PHP DTO class (provenance hint)'])]
    public ?string $dtoClass = null;

    #[ORM\Column(length: 255, nullable: true, options: ['comment' => 'Human-readable display name'])]
    public ?string $label = null;

    #[ORM\Column(type: 'text', nullable: true, options: ['comment' => 'Table description'])]
    public ?string $description = null;

    #[ORM\Column(options: ['default' => 0, 'comment' => 'Number of rows matching this table'])]
    public int $rowCount = 0;

    public function __construct(Folio $folio, string $coreCode, string $name, string $kind)
    {
        $this->folio = $folio;
        $this->coreCode = $coreCode;
        $this->name = $name;
        $this->kind = $kind;
        $this->id = $folio->code . ':' . $name;
    }
}
