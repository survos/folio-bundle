<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Metadata\ContentType;

use function Symfony\Component\String\u;

final class FolioDtoTypeResolver
{
    public function typeFromPayload(array $payload): string
    {
        $type = $payload['contentType'] ?? $payload['content_type'] ?? null;
        if (!is_string($type) || trim($type) === '') {
            throw new \InvalidArgumentException('Folio ingest requires normalized rows to include a non-empty "contentType" field.');
        }

        return $this->normalizeType($type);
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
