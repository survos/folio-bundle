<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'schema_property')]
#[ORM\UniqueConstraint(name: 'uniq_schema_property_table_name', columns: ['table_id', 'name'])]
#[ORM\Index(columns: ['name'])]
#[ORM\Index(columns: ['searchable'])]
#[ORM\Index(columns: ['facet'])]
class SchemaProperty
{
    #[ORM\Id]
    #[ORM\Column(length: 420, options: ['comment' => 'Composite: tableId:propertyName'])]
    public string $id;

    #[ORM\ManyToOne(targetEntity: SchemaTable::class)]
    #[ORM\JoinColumn(name: 'table_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public SchemaTable $table;

    #[ORM\Column(length: 180, options: ['comment' => 'Field name as observed in dto_data'])]
    public string $name;

    #[ORM\Column(length: 180, nullable: true, options: ['comment' => 'Human-readable label'])]
    public ?string $label = null;

    #[ORM\Column(type: 'text', nullable: true, options: ['comment' => 'Field description from DTO or docblock'])]
    public ?string $description = null;

    #[ORM\Column(length: 80, nullable: true, options: ['comment' => 'Inferred or declared data type'])]
    public ?string $type = null;

    #[ORM\Column(name: 'declaring_class', length: 255, nullable: true, options: ['comment' => 'PHP class that declares this property'])]
    public ?string $declaringClass = null;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Profiler stats from jsonl-bundle'])]
    public ?array $stats = null;

    #[ORM\Column(options: ['default' => 0, 'comment' => 'Display order'])]
    public int $position = 0;

    #[ORM\Column(options: ['default' => true, 'comment' => 'Show in default UI views'])]
    public bool $visible = true;

    #[ORM\Column(options: ['default' => false, 'comment' => 'Include in full-text search'])]
    public bool $searchable = false;

    #[ORM\Column(options: ['default' => false, 'comment' => 'Available as a filter'])]
    public bool $filterable = false;

    #[ORM\Column(options: ['default' => false, 'comment' => 'Available as a facet'])]
    public bool $facet = false;

    #[ORM\Column(options: ['default' => false, 'comment' => 'Available for sorting'])]
    public bool $sortable = false;

    public function __construct(SchemaTable $table, string $name)
    {
        $this->table = $table;
        $this->name = $name;
        $this->label = $this->labelize($name);
        $this->id = $table->id . ':' . $name;
    }

    private function labelize(string $name): string
    {
        return ucwords((string) preg_replace('/[_-]+/', ' ', $name));
    }
}
