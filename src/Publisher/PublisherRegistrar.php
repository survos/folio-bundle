<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Publisher;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;

/**
 * The one way a consumer tells Harvest what it publishes: reset, confirm, withdraw, flush.
 *
 * Rules (Harvest docs/folio-changelog.md, "Publisher registration and receipts"):
 *
 *  - confirm only after the folio is published locally, with the revision actually published —
 *    initial and incremental sync use the same {@see confirm()};
 *  - receipts are queued in the durable {@see PublisherState} and {@see flush()}ed in batches of at
 *    most 500; anything Harvest has not acknowledged stays queued and is resent next run, which is
 *    safe because the endpoint is idempotent and ordered by occurredAt;
 *  - a 409 means Harvest was reset under us: queued receipts are discarded (never resent under the
 *    new generation) and the state asks for a full sync;
 *  - revisions are opaque, compared for equality only;
 *  - withdrawal is explicit; a dataset missing from the feed is withdrawn by the consumer calling
 *    {@see withdraw()} or {@see withdrawAbsent()}, never inferred here;
 *  - selection is intent, kept apart from receipts ({@see updateSelection()}).
 */
final readonly class PublisherRegistrar
{
    public function __construct(
        private PublisherApiClient $api,
        private PublisherSelection $selection = new PublisherSelection(),
        private ?string $label = null,
        private ?string $baseUrl = null,
        private ?LoggerInterface $logger = null,
        private ?ClockInterface $clock = null,
    ) {}

    /**
     * Load the checkpoint's publisher state and reconcile it with Harvest before a sync.
     *
     * No saved generation (first run, or the consumer lost its checkpoint) → reset: a fresh start is
     * exactly what reset is for. A saved generation Harvest no longer holds → adopt the current one,
     * drop everything queued, and require a full sync. Harvest unreachable with a saved generation →
     * carry on; the receipts wait in the queue.
     */
    public function begin(?array $saved): PublisherState
    {
        $state = PublisherState::fromArray($saved);
        if ($state->generation() === null) {
            return $this->reset($state);
        }
        try {
            $registration = $this->api->registration();
        } catch (HttpExceptionInterface $error) {
            $this->logger?->warning('Harvest publisher registration unavailable; receipts stay queued', ['error' => $error->getMessage()]);
            return $state;
        }
        if ($registration === null) {
            return $this->reset($state);
        }
        if ($registration->generation !== $state->generation()) {
            $this->logger?->notice('Harvest publisher generation changed; resynchronizing', ['saved' => $state->generation(), 'current' => $registration->generation]);
            $state->startGeneration($registration->generation);
        }
        return $state;
    }

    /** Register, or start over after losing local state (e.g. a purged database). Then full-sync. */
    public function reset(?PublisherState $state = null): PublisherState
    {
        $state ??= new PublisherState();
        $state->startGeneration($this->api->reset($this->selection, $this->label, $this->baseUrl)->generation);
        return $state;
    }

    public function registration(): ?PublisherRegistration
    {
        return $this->api->registration();
    }

    /** Selection is intent only; it never confirms or withdraws anything. */
    public function updateSelection(PublisherSelection $selection): PublisherRegistration
    {
        return $this->api->updateSelection($selection);
    }

    /**
     * Queue "published" for a catalog entry the consumer has just published locally (or found already
     * published during a sync). Returns false when that exact revision and URL are already confirmed.
     */
    public function confirm(PublisherState $state, FolioCatalogEntry $entry, string $url): bool
    {
        if ($entry->artifactType === null || $entry->revision === null) {
            throw new \InvalidArgumentException(sprintf('Catalog entry %s has no artifact type/revision; confirm entries read from the dataset API.', $entry->datasetKey));
        }
        return $this->confirmVariant($state, $entry->datasetKey, $entry->artifactType, $entry->artifactCode(), $entry->revision, $url);
    }

    public function confirmVariant(PublisherState $state, string $datasetKey, string $artifactType, string $artifactCode, string $revision, string $url): bool
    {
        $this->assertOnBaseHost($url);
        return $state->record(new PublicationReceipt($datasetKey, $artifactType, $artifactCode, ReceiptState::Published, $this->now(), $revision, $url));
    }

    public function withdraw(PublisherState $state, string $datasetKey, string $artifactType, string $artifactCode = PublicationReceipt::DEFAULT_CODE): void
    {
        $state->record(new PublicationReceipt($datasetKey, $artifactType, $artifactCode, ReceiptState::Withdrawn, $this->now()));
    }

    /**
     * Withdraw every confirmed variant that is not among the entries still published — for the
     * consumer that knows its complete published set after a sync (feed 404s, deselections).
     *
     * @param iterable<FolioCatalogEntry> $published
     */
    public function withdrawAbsent(PublisherState $state, iterable $published): int
    {
        $keep = [];
        foreach ($published as $entry) {
            if ($entry->artifactType !== null) {
                $keep[PublicationReceipt::variantKey($entry->datasetKey, $entry->artifactType, $entry->artifactCode())] = true;
            }
        }
        $withdrawn = 0;
        foreach ($state->confirmed() as $receipt) {
            if (!isset($keep[$receipt->variant()])) {
                $this->withdraw($state, $receipt->datasetKey, $receipt->artifactType, $receipt->artifactCode);
                ++$withdrawn;
            }
        }
        return $withdrawn;
    }

    /**
     * Send queued receipts. Never throws for a Harvest failure: whatever was not acknowledged stays
     * in the state, which the caller saves either way.
     */
    public function flush(PublisherState $state): PublisherFlushResult
    {
        if ($state->generation() === null) {
            return new PublisherFlushResult(remaining: count($state->pending()), error: 'Not registered with Harvest yet.');
        }
        $statuses = [];
        $batches = 0;
        foreach (array_chunk($state->pending(), PublisherApiClient::MAX_BATCH) as $batch) {
            try {
                $results = $this->api->receipts($state->generation(), $batch);
            } catch (StaleGenerationException) {
                return $this->afterStaleGeneration($state, $statuses, $batches);
            } catch (HttpExceptionInterface|\UnexpectedValueException $error) {
                $this->logger?->warning('Harvest receipts not acknowledged; they stay queued', ['error' => $error->getMessage(), 'pending' => count($state->pending())]);
                return new PublisherFlushResult($statuses, $batches, count($state->pending()), error: $error->getMessage());
            }
            ++$batches;
            foreach ($batch as $i => $receipt) {
                if ($results[$i] === ReceiptStatus::Unknown) {
                    $this->logger?->notice('Harvest no longer has this folio variant', ['datasetKey' => $receipt->datasetKey, 'artifactType' => $receipt->artifactType]);
                }
                $state->acknowledge($receipt, $results[$i]);
                $statuses[$results[$i]->value] = ($statuses[$results[$i]->value] ?? 0) + 1;
            }
        }
        return new PublisherFlushResult($statuses, $batches, count($state->pending()));
    }

    /** @param array<string, int> $statuses */
    private function afterStaleGeneration(PublisherState $state, array $statuses, int $batches): PublisherFlushResult
    {
        $stale = (int) $state->generation();
        // Drop pre-reset receipts now. If Harvest is unreachable for the re-read, begin() adopts the
        // new generation next run because the saved one no longer matches.
        $state->startGeneration($stale);
        try {
            $registration = $this->api->registration();
            $registration === null ? $this->reset($state) : $state->startGeneration($registration->generation);
        } catch (HttpExceptionInterface $error) {
            $this->logger?->warning('Cannot re-read the publisher registration after a 409', ['error' => $error->getMessage()]);
        }
        $this->logger?->notice('Harvest publisher generation is stale; queued receipts discarded, full sync required', ['stale' => $stale, 'current' => $state->generation()]);
        return new PublisherFlushResult($statuses, $batches, 0, staleGeneration: true);
    }

    private function assertOnBaseHost(string $url): void
    {
        if ($this->baseUrl === null || $this->baseUrl === '') {
            return;
        }
        if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST))) {
            throw new \InvalidArgumentException(sprintf('Publication URL %s is not on the registered base URL %s.', $url, $this->baseUrl));
        }
    }

    private function now(): string
    {
        // Microseconds: Harvest orders a variant's events by this instant, and keeps the precision.
        return ($this->clock?->now() ?? new \DateTimeImmutable())->format('Y-m-d\TH:i:s.uP');
    }
}
