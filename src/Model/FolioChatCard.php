<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Model;

/**
 * One item the AI chose to cite, paired with its folio row. The {@see $caption} is the AI's own
 * natural-language description of why this item answers the question — the "voice" of the response,
 * shown alongside the row's photo. The {@see $hit} carries the backing database record (label, id,
 * links), rendered as the supporting footnote beneath the AI narration.
 */
final readonly class FolioChatCard
{
    public function __construct(
        public FolioChatHit $hit,
        public ?string $caption = null,
    ) {}
}
