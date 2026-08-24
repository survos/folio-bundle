<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Model\FolioContext;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Holds the folio a chat turn is running against, so the agent's tools ({@see FolioChatTools}) know
 * which SQLite file to search. {@see FolioChatService} sets it around each agent call and clears it
 * after. One PHP process serves one request at a time, so a plain mutable holder is safe --
 * but under FrankenPHP worker mode that process serves the *next* request too, so the holder
 * is reset at the request boundary rather than trusting every caller's clear-after.
 */
final class FolioChatContextHolder implements ResetInterface
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

    public function reset(): void
    {
        $this->current = null;
    }
}
