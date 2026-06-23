<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Twig;

use Survos\DataContracts\Vocabulary\Core;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig helpers for folio cores. `core_icon('obj')` → a Tabler icon name for the canonical Core
 * codes, so templates don't each carry their own code→icon map. Pass the result to ux_icon().
 */
final class FolioCoreTwig
{
    /** Canonical Core code → Tabler icon name. */
    private const ICONS = [
        Core::OBJECT       => 'tabler:box',
        Core::DOCUMENT     => 'tabler:file-text',
        Core::IMAGE        => 'tabler:photo',
        Core::COLLECTION   => 'tabler:folders',
        Core::SERIES       => 'tabler:stack-2',
        Core::PERSON       => 'tabler:user',
        Core::PLACE        => 'tabler:map-pin',
        Core::ORGANISATION => 'tabler:building',
        Core::EVENT        => 'tabler:calendar-event',
    ];

    #[AsTwigFunction('core_icon')]
    public function coreIcon(string $coreCode): string
    {
        return self::ICONS[$coreCode] ?? 'tabler:database';
    }
}
