<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Publisher;

/**
 * The publisher half of a consumer's durable checkpoint.
 *
 * Saved as one JSON value next to the sync cursor ({@see toArray()} / {@see fromArray()}):
 *
 *  - generation: the Harvest generation every pending receipt was produced under;
 *  - pending: receipts Harvest has not acknowledged yet, one per variant (the latest wins). They are
 *    resent on every flush until acknowledged, so an outage loses nothing;
 *  - confirmed: the published receipt last recorded per variant, so a re-run does not re-confirm an
 *    unchanged folio and withdrawals know what exists;
 *  - resyncRequired: set by a reset or a 409 until the consumer finishes a full sync.
 *
 * Mutable on purpose: a sync's apply callback confirms folios one by one into the same object.
 */
final class PublisherState
{
    /**
     * @param array<string, PublicationReceipt> $pending   by variant key, oldest first
     * @param array<string, PublicationReceipt> $confirmed by variant key, published receipts only
     */
    public function __construct(
        private ?int $generation = null,
        private array $pending = [],
        private array $confirmed = [],
        private bool $resyncRequired = false,
    ) {}

    public static function fromArray(?array $saved): self
    {
        $state = new self(isset($saved['generation']) ? (int) $saved['generation'] : null, resyncRequired: (bool) ($saved['resyncRequired'] ?? false));
        foreach ($saved['confirmed'] ?? [] as $row) {
            $receipt = PublicationReceipt::fromArray($row);
            $state->confirmed[$receipt->variant()] = $receipt;
        }
        foreach ($saved['pending'] ?? [] as $row) {
            $receipt = PublicationReceipt::fromArray($row);
            $state->pending[$receipt->variant()] = $receipt;
        }
        return $state;
    }

    public function toArray(): array
    {
        $rows = static fn (array $receipts): array => array_values(array_map(static fn (PublicationReceipt $r): array => $r->toArray(), $receipts));
        return [
            'version' => 1,
            'generation' => $this->generation,
            'resyncRequired' => $this->resyncRequired,
            'pending' => $rows($this->pending),
            'confirmed' => $rows($this->confirmed),
        ];
    }

    public function generation(): ?int { return $this->generation; }

    /** True after a reset or a stale-generation 409, until {@see markResynced()}. Run a full sync. */
    public function resyncRequired(): bool { return $this->resyncRequired; }

    /** Call after a full sync (and its confirms) completed. */
    public function markResynced(): void { $this->resyncRequired = false; }

    /** @return list<PublicationReceipt> oldest queued first */
    public function pending(): array { return array_values($this->pending); }

    /** @return list<PublicationReceipt> */
    public function confirmed(): array { return array_values($this->confirmed); }

    public function confirmedRevision(string $datasetKey, string $artifactType, string $artifactCode = PublicationReceipt::DEFAULT_CODE): ?string
    {
        return $this->confirmed[PublicationReceipt::variantKey($datasetKey, $artifactType, $artifactCode)]->revision ?? null;
    }

    /**
     * A new generation: everything queued or confirmed before it describes pre-reset state and is
     * dropped, never carried over.
     */
    public function startGeneration(int $generation): void
    {
        $this->generation = $generation;
        $this->pending = [];
        $this->confirmed = [];
        $this->resyncRequired = true;
    }

    /** Queue a receipt; returns false when it would only repeat what is already confirmed. */
    public function record(PublicationReceipt $receipt): bool
    {
        $key = $receipt->variant();
        if ($receipt->isPublished()) {
            if (!isset($this->pending[$key]) && isset($this->confirmed[$key]) && $this->confirmed[$key]->sameAs($receipt)) {
                return false;
            }
            $this->confirmed[$key] = $receipt;
        } else {
            unset($this->confirmed[$key]);
        }
        // Re-queue at the end so batches stay in the order events happened.
        unset($this->pending[$key]);
        $this->pending[$key] = $receipt;
        return true;
    }

    /** Harvest answered this exact receipt; every status is an acknowledgement. */
    public function acknowledge(PublicationReceipt $receipt, ReceiptStatus $status): void
    {
        $key = $receipt->variant();
        // Only drop it if nothing newer was queued for the variant meanwhile.
        if (($this->pending[$key] ?? null) === $receipt) {
            unset($this->pending[$key]);
        }
        // Harvest has no such variant: it was deleted there, so it is not ours to publish either.
        if ($status === ReceiptStatus::Unknown && isset($this->confirmed[$key]) && $this->confirmed[$key]->sameAs($receipt)) {
            unset($this->confirmed[$key]);
        }
    }
}
