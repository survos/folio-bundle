<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Model\FolioContext;

/**
 * Holds the folio a chat turn is running against, so the agent's tools ({@see FolioChatTools}) know
 * which SQLite file to search. {@see FolioChatService} sets it around each agent call and clears it
 * after. One PHP process serves one request at a time, so a plain mutable holder is safe.
 */
final class FolioChatContextHolder
{
    private ?FolioContext $current = null;

    public function set(?FolioContext $context): void
    {
        $this->current = $context;
    }

    public function get(): ?FolioContext
    {
        return $this->current;
    }

    public function require(): FolioContext
    {
        return $this->current ?? throw new \RuntimeException('No folio chat context is active.');
    }
}
