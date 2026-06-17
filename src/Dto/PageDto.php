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
     * @param array<string,mixed>|null $ledger
     * @param array<string,mixed>|null $layout
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
        public ?int $width = null,
        public ?int $height = null,
    ) {
    }
}
