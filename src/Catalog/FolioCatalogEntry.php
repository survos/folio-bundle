<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Catalog;

/**
 * One published folio as the hub describes it.
 *
 * This is the whole reason the client is typed. Three apps grew their own readers of the same
 * catalog — ink's FolioCatalog, fotostory's FolioCatalogClient, mastheads' InkCatalog — against
 * three different endpoints, and each decided separately which keys mattered and what a missing
 * one meant. A record with named, typed fields makes that one decision, in one place.
 *
 * Every field is optional in the payload and defaulted here on purpose: the catalog is a remote
 * service that has already changed shape once, and a reader should degrade (no tags, no size)
 * rather than fatal on a key that a deployment has not shipped yet.
 */
final readonly class FolioCatalogEntry
{
    /**
     * @param list<string> $tags editorial/classification tags a folio set selects on
     */
    public function __construct(
        public string $datasetKey,
        public string $provider,
        public string $code,
        public string $title,
        public ?string $description = null,
        public ?string $locale = null,
        public array $tags = [],
        public ?string $contentType = null,
        public int $rowCount = 0,
        public ?int $sizeBytes = null,
        public ?string $checksum = null,
        public ?string $updatedAt = null,
        public ?string $downloadUrl = null,
        public bool $compressed = false,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): ?self
    {
        $datasetKey = self::str($row, 'datasetKey');
        if ($datasetKey === null) {
            // Without an identity the entry cannot be matched, pulled or linked to. Skip it rather
            // than minting a blank key that would collide with every other blank one.
            return null;
        }

        // provider/code are published separately, but a datasetKey is always "<provider>/<code>" —
        // derive rather than require, so one endpoint's omission is not the caller's problem.
        [$provider, $code] = array_pad(explode('/', $datasetKey, 2), 2, '');

        return new self(
            datasetKey: $datasetKey,
            provider: self::str($row, 'provider') ?? $provider,
            code: self::str($row, 'code') ?? $code,
            title: self::str($row, 'title') ?? self::str($row, 'label') ?? $datasetKey,
            description: self::str($row, 'description'),
            locale: self::str($row, 'locale'),
            tags: array_values(array_filter(array_map(
                static fn (mixed $t): string => strtolower(trim((string) $t)),
                is_array($row['tags'] ?? null) ? $row['tags'] : [],
            ), static fn (string $t): bool => $t !== '')),
            contentType: self::str($row, 'contentType'),
            rowCount: (int) ($row['rowCount'] ?? 0),
            sizeBytes: isset($row['sizeBytes']) ? (int) $row['sizeBytes'] : null,
            checksum: self::str($row, 'checksum'),
            updatedAt: self::str($row, 'updatedAt'),
            downloadUrl: self::str($row, 'downloadUrl'),
            compressed: (bool) ($row['compressed'] ?? false),
        );
    }

    public function hasTag(string $tag): bool
    {
        return in_array(strtolower(trim($tag)), $this->tags, true);
    }

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
