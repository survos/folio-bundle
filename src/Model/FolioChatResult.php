<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Model;

final readonly class FolioChatResult
{
    /**
     * @param list<FolioChatHit>  $hits  every retrieved row (raw search results)
     * @param list<FolioChatCard> $cards the AI-cited rows, each with the AI's caption + its photo/record
     */
    public function __construct(
        public string $question,
        public string $answer,
        public string $contextPrompt,
        public array $hits,
        public array $cards = [],
        public ?string $error = null,
    ) {}
}
