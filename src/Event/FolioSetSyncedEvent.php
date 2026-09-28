<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Event;

/**
 * Dispatched by folio:sets:sync after a set's membership is resolved and its files are settled.
 * This is the app's hook for its own database: upsert what it keeps per folio (fotostory's tenants,
 * ink's publications) for every available member, and remove or hide what is gone. Membership
 * itself is decided here, never by the listener. See docs/folio-sets.md.
 */
final readonly class FolioSetSyncedEvent
{
    /**
     * @param array{label: ?string, core: string, criteria: array<string, mixed>} $set
     * @param list<array<string, mixed>> $members each with datasetKey, label, available, local, ...
     */
    public function __construct(
        public string $code,
        public array $set,
        public array $members,
    ) {}

    /** @return list<array<string, mixed>> */
    public function available(): array
    {
        return array_values(array_filter($this->members, static fn (array $m): bool => $m['available'] ?? false));
    }

    public function isSingle(): bool
    {
        return count($this->members) === 1;
    }
}
