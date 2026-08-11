<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Twig\Components;

use Survos\DataContracts\Dto\Item\BaseItemDto;
use Survos\FieldBundle\Model\FieldDescriptor;
use Survos\FieldBundle\Service\FieldReader;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;

/**
 * Renders a row's typed DTO as flowing prose (Cooper Hewitt object-page style — see
 * https://collection.cooperhewitt.org/objects/18693389/) instead of a field/value datasheet.
 * Replaces folio/detail.html.twig's giant "DTO Data" table.
 *
 * $dto is BaseItemDto::fromNormalized($row->dtoData) — already hydrated by
 * FolioController::rowShow(). Every BaseItemDto property (title, description, creators, date,
 * institution, rights, …) is either spoken here as a sentence or shown elsewhere on the page
 * (title in the hero, subjects/genre as Terms, media fields by the viewer); getDetailFacts()
 * surfaces whatever's left — almost always just the content-type-specific properties a subclass
 * adds (PhotographDto::$donor, PostcardDto::$postmarkDate, BookDto::$isbn, …) — grouped by their
 * #[Field(group: …)] attribute, so adding a new content-type DTO needs no template changes here.
 *
 * Usage: <twig:folio:narrative dto="{{ dto }}" />
 */
#[AsTwigComponent(name: 'folio:narrative', template: '@SurvosFolioBundle/components/FolioNarrative.html.twig')]
final class FolioNarrative
{
    public ?BaseItemDto $dto = null;

    /** @var list<string>|null cached property names declared directly on BaseItemDto */
    private static ?array $baseItemPropertyNames = null;

    public function __construct(
        private readonly FieldReader $fieldReader,
    ) {
    }

    /**
     * The main descriptive paragraph — delegates to BaseItemDto::mainText(), the single source of
     * truth shared with FolioController::rowShow()'s markdown-response bypass for bots.
     *
     * Silently dropping searchSummary entirely was an actual bug (2026-08-06): getDetailFacts()
     * also never surfaces it, since every BaseItemDto property is assumed "spoken here or shown
     * elsewhere" (see baseItemPropertyNames()) -- so an item with only searchSummary populated
     * showed zero descriptive text anywhere on the page.
     */
    #[ExposeInTemplate]
    public function getMainText(): ?string
    {
        return $this->dto?->mainText();
    }

    #[ExposeInTemplate]
    public function isMainTextMarkdown(): bool
    {
        return $this->dto !== null && $this->dto->mainTextIsMarkdown();
    }

    /** Plain-text source caption, only when it isn't already the main text shown above. */
    #[ExposeInTemplate]
    public function getSourceCaption(): ?string
    {
        $dto = $this->dto;
        if ($dto === null || $dto->sourceCaption === null) {
            return null;
        }

        return $dto->sourceCaption === $this->getMainText() ? null : $dto->sourceCaption;
    }

    /** "City, State, Country" — same join FolioItem.html.twig uses for its tombstone location. */
    #[ExposeInTemplate]
    public function getLocation(): ?string
    {
        $dto = $this->dto;
        if ($dto === null) {
            return null;
        }

        $parts = array_filter([$dto->city, $dto->state, $dto->country], static fn (?string $v): bool => $v !== null && $v !== '');

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Leftover facts not already spoken as a sentence or shown elsewhere on the page (Terms,
     * viewer, hero) — grouped by #[Field(group: …)], in declaration order.
     *
     * @return array<string, list<array{label: string, domain: string, value: mixed}>>
     */
    #[ExposeInTemplate]
    public function getDetailFacts(): array
    {
        $dto = $this->dto;
        if ($dto === null) {
            return [];
        }

        $excluded = self::baseItemPropertyNames();
        $groups = [];

        foreach ($this->fieldReader->getDescriptors($dto::class) as $descriptor) {
            if (in_array($descriptor->name, $excluded, true)) {
                continue;
            }

            $value = $dto->{$descriptor->name} ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $groups[$descriptor->group ?? ''][] = [
                'label' => $descriptor->getTranslationKey(),
                'domain' => $descriptor->getTranslationDomain(),
                'fallbackLabel' => $descriptor->getFallbackLabel(),
                'value' => $value,
            ];
        }

        return $groups;
    }

    /**
     * Every property BaseItemDto itself declares — these are always either spoken as a sentence
     * by this component, or rendered elsewhere (title in the hero, subjects/genre as Terms, media
     * fields by the viewer/OCR panel). Only a subclass's OWN properties (donor, isbn, …) fall
     * through to getDetailFacts().
     *
     * @return list<string>
     */
    private static function baseItemPropertyNames(): array
    {
        return self::$baseItemPropertyNames ??= array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(BaseItemDto::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
    }
}
