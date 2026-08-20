<?php
declare(strict_types=1);

namespace Survos\FolioBundle\Dto;

use Survos\FolioBundle\Enum\PageType;

/**
 * One line of the page stream (page.jsonl) → one Page entity (the canonical imagery of a folio Row).
 *
 * Mirrors the serialisable Page fields plus {coreCode, localId}, which the ingester resolves to the
 * parent Row (Row::id("$datasetKey:$coreCode", $localId)). Single shared contract between producers
 * (normalize emitters) and the consumer (FolioIngestService::ingestPages) so the schema and filename
 * can't drift. Pass it straight to JsonlWriter::write() — it serialises the DTO (null fields dropped).
 */
final readonly class PageDto
{
    /** Canonical page-stream filename. Reference this everywhere to avoid page/pages drift. */
    public const string FILENAME = 'page.jsonl';

    /**
     * The stream's stem, as the stage pipeline names cores.
     *
     * Not a {@see \Survos\DataContracts\Vocabulary\Core} constant — a page is never browsed as a
     * core (no /folio/{…}/page/ URL); it is the imagery *of* a row. But normalize, enrich and
     * folio:build all address it positionally the way they address obj/doc, so it needs a stem.
     */
    public const string CORE = 'page';

    /**
     * @param array<string,mixed>|null $ledger
     * @param array<string,mixed>|null $layout
     * @param list<array{speaker: ?string, text: string, startMs: ?int, endMs: ?int}>|null $dialogue
     */
    public function __construct(
        public string $coreCode,
        public string $localId,
        public string $url,
        public int $seq = 1,
        public int $pageIndex = 0,
        public ?PageType $type = null,
        public ?string $mediaId = null,
        public ?string $text = null,
        public ?string $denseSummary = null,
        public ?array $ledger = null,
        public ?array $layout = null,
        public ?array $dialogue = null,
        public ?int $width = null,
        public ?int $height = null,
        /**
         * Where the image originally came from, when $url no longer says so.
         *
         * $url is what the viewer fetches — our archived S3 copy once mediary has one. That
         * rewrite would otherwise destroy the provenance the citation needs, so the origin moves
         * here rather than being overwritten. Null means $url is still the origin (nothing has
         * been archived for this page yet, or the dataset is deliberately not archived).
         */
        public ?string $sourceUrl = null,
        /**
         * Exif-like objective facts known from the catalog (date, city, country, …) — the "known"
         * half of observation (what's objectively true), distinct from the "seen" pixels. Always
         * passed when known, for any provider. For scanned negatives these OVERRIDE the misleading
         * file EXIF (which reflects digitization, not capture).
         *
         * @var array<string,mixed>|null
         */
        public ?array $known = null,
    ) {
    }
}
