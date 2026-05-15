<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Survos\FolioBundle\Repository\FolioRepository;

#[ORM\Entity(repositoryClass: FolioRepository::class)]
#[ORM\Table(name: 'folio')]
class Folio
{
    #[ORM\Id]
    #[ORM\Column(length: 120)]
    public string $code;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $label = null;

    #[ORM\Column(length: 180, nullable: true)]
    public ?string $datasetKey = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $rowCount = 0;

    public function __construct(string $code) { $this->code = $code; }
}
