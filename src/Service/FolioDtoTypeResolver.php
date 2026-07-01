<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Dto\Item\BaseItemDto;
use Survos\DataContracts\Metadata\ContentType;

use function Symfony\Component\String\u;

final class FolioDtoTypeResolver
{
    public function typeFromPayload(array $payload): string
    {
        $type = $payload['contentType'] ?? $payload['content_type'] ?? null;
        if (is_string($type) && trim($type) !== '') {
            return $this->normalizeType($type);
        }

        // No explicit contentType — derive it from the row's DTO class. Every Item DTO declares its
        // ContentType statically (PhotographDto::contentType() === 'photograph'), so a row that knows
        // its dtoClass never needs a separate field: "it's a photograph" is implied by PhotographDto.
        $dtoClass = $payload['dtoClass'] ?? null;
        if (is_string($dtoClass) && is_a($dtoClass, BaseItemDto::class, true)) {
            return $this->normalizeType($dtoClass::contentType());
        }

        throw new \InvalidArgumentException('Folio ingest requires rows to carry a "contentType", or a dtoClass to derive it from.');
    }

    public function classForType(?string $type): ?string
    {
        if (!$type) {
            return null;
        }

        $class = ContentType::dtoClass($type);

        return class_exists($class) ? $class : null;
    }

    public function labelForType(?string $type): string
    {
        if (!$type) {
            return '(none)';
        }

        return (string) u($type)->replace('-', ' ')->replace('_', ' ')->title();
    }

    private function normalizeType(string $value): string
    {
        return u(trim($value))->snake()->lower()->toString();
    }
}
