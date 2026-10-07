<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Api;

final readonly class WallCardCollection
{
    /** @param list<WallCard> $cards */
    public function __construct(
        public array $cards,
        public array $folio,
        public int $total,
        public int $page,
        public int $itemsPerPage,
        public string $path,
        public array $query,
        public string $contextUrl,
        public string $itemPath,
    ) {}
}
