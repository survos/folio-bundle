<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Dto\Item\BaseItemDto;
use Survos\DataContracts\Metadata\ContentType;

use function Symfony\Component\String\u;

final class FolioDtoTypeResolver
{
    /**
     * Content types whose row page reads better with the transcription/OCR panel as the primary
     * content and the image viewer secondary — the opposite emphasis of a photograph. Its own
     * taxonomy, not ContentType::OCR_TYPES: that constant drives a pipeline decision ("run OCR"),
     * this drives a display decision ("row/document.html.twig instead of row/show.html.twig"),
     * and the two lists happening to overlap today shouldn't couple them.
     */
    private const array DOCUMENT_TYPES = [
        ContentType::BOOK,
        ContentType::NEWSPAPER,
        ContentType::PERIODICAL,
        ContentType::MANUSCRIPT,
        ContentType::CORRESPONDENCE,
        ContentType::DOCUMENT,
        ContentType::EPHEMERA,
        ContentType::POSTCARD,
    ];

    /**
     * Twig template-name candidates for a row's dtoType, most specific first, always ending in
     * 'show' (folio/row/show.html.twig, which always exists) — pass to
     * Twig\Environment::resolveTemplate(), which renders the first one that's actually defined.
     * Lets a content type opt into a more specific row/{type}.html.twig (which itself extends
     * row/document.html.twig or row/show.html.twig) without every type needing its own template.
     *
     * @return list<string>
     */
    public function templateCandidates(?string $dtoType): array
    {
        $candidates = [];

        if ($dtoType) {
            $candidates[] = $dtoType;

            if (in_array($dtoType, self::DOCUMENT_TYPES, true)) {
                $candidates[] = 'document';
            }
        }

        $candidates[] = 'show';

        return $candidates;
    }

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
