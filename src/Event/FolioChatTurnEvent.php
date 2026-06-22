<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Event;

use Survos\FolioBundle\Model\FolioChatResult;
use Survos\FolioBundle\Model\FolioContext;
use Symfony\Contracts\EventDispatcher\Event;

final class FolioChatTurnEvent extends Event
{
    public function __construct(
        public readonly FolioContext $context,
        public readonly string $conversationId,
        public readonly FolioChatResult $result,
    ) {}
}
