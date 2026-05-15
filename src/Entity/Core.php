<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\FolioBundle\Repository\CoreRepository;

#[ORM\Entity(repositoryClass: CoreRepository::class)]
#[ORM\Table(name: 'core')]
#[ORM\UniqueConstraint(name: 'uniq_core_code', columns: ['folio_code', 'code'])]
class Core
{
    #[ORM\Id]
    #[ORM\Column(length: 180)]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Folio::class)]
    #[ORM\JoinColumn(name: 'folio_code', referencedColumnName: 'code', nullable: false, onDelete: 'CASCADE')]
    public Folio $folio;

    #[ORM\Column(length: 80)]
    public string $code;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $label = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $rowCount = 0;

    public function __construct(Folio $folio, string $code)
    {
        $this->folio = $folio;
        $this->code = $code;
        $this->id = self::id($folio->code, $code);
    }

    public static function id(string $folioCode, string $coreCode): string { return "$folioCode:$coreCode"; }
}
