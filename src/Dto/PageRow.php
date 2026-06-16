<?php
declare(strict_types=1);

namespace Survos\FolioBundle\Dto;

/**
 * One row of the page stream — the canonical imagery of a folio Row (→ the `page` table).
 *
 * Mirrors the serialisable fields of the Page entity plus {coreCode, localId}, which the
 * ingester resolves to the parent Row (Row::id("$datasetKey:$coreCode", $localId)). This is
 * the single shared contract between producers (normalize listeners emitting page.jsonl) and
 * the consumer (FolioIngestService::ingestPages), so the schema and filename can't drift.
 *
 * It's a plain field-for-field DTO: serialise it with the Symfony normalizer, no toArray().
 */
final readonly class PageRow
{
    /** Canonical page-stream filename. Reference this everywhere to avoid page/pages drift. */
    public const string FILENAME = 'page.jsonl';

    /**
     * @param array<string,mixed>|null $ledger
     * @param array<string,mixed>|null $layout
     */
    public function __construct(
        public string $coreCode,
        public string $localId,
        public string $url,
        public int $seq = 1,
        public int $pageIndex = 0,
        public ?string $mediaId = null,
        public ?string $text = null,
        public ?string $denseSummary = null,
        public ?array $ledger = null,
        public ?array $layout = null,
        public ?int $width = null,
        public ?int $height = null,
    ) {
    }
}
