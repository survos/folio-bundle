<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Publisher;

/**
 * Harvest answered 409: the receipts were written against a generation that has since been reset.
 * Nothing was stored. Never resend them under the new number — they may describe pre-reset state.
 */
final class StaleGenerationException extends \RuntimeException
{
    public function __construct(public readonly int $sentGeneration, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Publisher generation %d is no longer current.', $sentGeneration), 409, $previous);
    }
}
