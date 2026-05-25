<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Model;

final readonly class FolioChatResult
{
    /**
     * @param list<FolioChatHit> $hits
     */
    public function __construct(
        public string $question,
        public string $answer,
        public string $contextPrompt,
        public array $hits,
    ) {}
}
