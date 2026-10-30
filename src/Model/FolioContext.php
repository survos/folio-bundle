<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Model;

use Doctrine\ORM\EntityManagerInterface;
use Survos\FolioBundle\Service\DuckDbCompanion;

final readonly class FolioContext
{
    public function __construct(
        public string $folioCode,
        public string $path,
        public EntityManagerInterface $em,
        private ?DuckDbCompanion $companion = null,
    ) {
    }

    /** Optional, read-only PDO for this exact selected build. ORM access is unchanged. */
    public function duckdb(): ?\PDO
    {
        return $this->companion?->open();
    }
}
