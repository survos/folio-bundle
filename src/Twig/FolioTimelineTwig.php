<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Twig;

use Survos\FolioBundle\Service\FolioTimelineStats;
use Twig\Attribute\AsTwigFunction;

/**
 * `folio_timeline_stats(folioCode)` → {min, max, counts}|null -- the reusable numbers behind
 * any timeline/histogram widget over a folio. See FolioTimelineStats's own docblock for why this
 * is separate from openfoto's app-level TenantController::timelineData().
 */
final class FolioTimelineTwig
{
    public function __construct(private readonly FolioTimelineStats $stats)
    {
    }

    /** @return array{min: int, max: int, counts: array<int, int>}|null */
    #[AsTwigFunction('folio_timeline_stats')]
    public function timelineStats(string $folioCode, ?string $coreCode = null): ?array
    {
        return $this->stats->forFolio($folioCode, $coreCode);
    }
}
