<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Exception;

/**
 * Another process is already migrating this folio and did not finish within the wait window.
 *
 * Additive migrations (ALTER TABLE … ADD COLUMN) are metadata-only in SQLite and measure ~25 ms
 * regardless of folio size — 0.3 MB and 25 MB folios cost the same (measured 2026-08-25 across the
 * pending `page.source_url` backlog). Those finish inside the wait window, so callers never see
 * this; the lock just stops two concurrent opens from doing the same work twice.
 *
 * This exists for the OTHER class of migration — one that has to rewrite or rebuild the folio and
 * therefore cannot be done on a web request. Callers should translate it to a 503 with a
 * Retry-After header (NOT 429: that means "you sent too many requests", and Cloudflare and
 * crawlers treat it as rate-limiting; 503 + Retry-After is the "temporarily unavailable, come
 * back" semantic that browsers and Googlebot handle correctly, which matters for folio sitemaps).
 */
final class FolioMigrationInProgressException extends \RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly int $waitedMs,
    ) {
        parent::__construct(sprintf(
            'Folio "%s" is being migrated by another process (waited %d ms).',
            $path,
            $waitedMs,
        ));
    }
}
