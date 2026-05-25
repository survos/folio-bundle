<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\FolioBundle\Repository\CoreRepository;

#[ORM\Entity(repositoryClass: CoreRepository::class)]
#[ORM\Table(name: 'core')]
#[ORM\UniqueConstraint(name: 'uniq_core_code', columns: ['folio_code', 'code'])]
class Core
{
    #[ORM\Id]
    #[ORM\Column(length: 180, options: ['comment' => 'Composite: folioCode:coreCode'])]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Folio::class)]
    #[ORM\JoinColumn(name: 'folio_code', referencedColumnName: 'code', nullable: false, onDelete: 'CASCADE')]
    public Folio $folio;

    #[ORM\Column(length: 80, options: ['comment' => 'Core code within the folio (e.g. obj, per)'])]
    public string $code;

    #[ORM\Column(length: 255, nullable: true, options: ['comment' => 'Human-readable display name'])]
    public ?string $label = null;

    #[ORM\Column(options: ['default' => 0, 'comment' => 'Number of rows in this core'])]
    public int $rowCount = 0;

    #[ORM\Column(type: Types::JSON, nullable: true, options: ['comment' => 'Populated field names from normalized profile'])]
    public ?array $fieldSummary = null;

    public function __construct(Folio $folio, string $code)
    {
        $this->folio = $folio;
        $this->code = $code;
        $this->id = self::id($folio->code, $code);
    }

    public static function id(string $folioCode, string $coreCode): string { return "$folioCode:$coreCode"; }
}
