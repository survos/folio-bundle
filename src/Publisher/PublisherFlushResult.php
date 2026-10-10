<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Publisher;

/** What one {@see PublisherRegistrar::flush()} achieved. Unsent receipts stay pending in the state. */
final readonly class PublisherFlushResult
{
    /**
     * @param array<string, int> $statuses acknowledged receipts, keyed by {@see ReceiptStatus} value
     */
    public function __construct(
        public array $statuses = [],
        public int $batches = 0,
        public int $remaining = 0,
        public bool $staleGeneration = false,
        public ?string $error = null,
    ) {}

    public function count(ReceiptStatus $status): int { return $this->statuses[$status->value] ?? 0; }

    public function acknowledged(): int { return array_sum($this->statuses); }

    /** Everything queued reached Harvest. */
    public function isComplete(): bool { return $this->remaining === 0 && !$this->staleGeneration && $this->error === null; }
}
