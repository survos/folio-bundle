<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Model;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * The model's structured answer to a folio chat question — a short synthesis plus the items it
 * cites. Used as a `response_format` target so the model is constrained to this shape by the
 * platform's JSON schema, rather than by "return JSON" instructions stuffed into the prompt.
 */
final class FolioChatAnswer
{
    /**
     * @param list<FolioChatAnswerItem> $items
     */
    public function __construct(
        #[Schema(description: 'A 1-3 sentence answer to the question, synthesizing across the cited items.')]
        public string $summary = '',
        /** @var list<FolioChatAnswerItem> */
        public array $items = [],
    ) {}
}
