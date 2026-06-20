<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Model;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * One item the model chooses to cite in a {@see FolioChatAnswer}. The model copies the item's
 * localId from the candidate list, so the service can map it back to the folio row (for the photo
 * and the supporting record). This is a structured-output target — public typed props become the
 * JSON schema sent to the model; do not add behavior.
 */
final class FolioChatAnswerItem
{
    public function __construct(
        #[Schema(description: 'The exact localId of the cited item, copied verbatim from the candidate list.')]
        public string $localId = '',
        #[Schema(description: 'One or two natural sentences on this item and why it answers the question, grounded only in its title/description.')]
        public string $caption = '',
    ) {}
}
