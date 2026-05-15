<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Model;

use Doctrine\ORM\EntityManagerInterface;

final readonly class FolioContext
{
    public function __construct(
        public string $folioCode,
        public string $path,
        public EntityManagerInterface $em,
    ) {}
}
