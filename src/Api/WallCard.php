<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Api;

use ApiPlatform\Metadata\ApiResource;

/** The public wall-label contract. No source DTO, extras, OCR or provenance payloads. */
#[ApiResource(shortName: 'WallCard', operations: [])]
final readonly class WallCard
{
    public function __construct(
        public string $id,
        public ?string $title,
        public array $label,
        public ?int $year,
        public ?array $geo,
        public ?array $size,
        public ?array $image,
        public array $audio,
        public ?string $sourceUrl,
        public ?string $license,
    ) {}
}
