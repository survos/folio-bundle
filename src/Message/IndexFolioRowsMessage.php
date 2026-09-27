<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Message;

/**
 * Index one folio's rows into the shared Elasticsearch row index
 * ({@see \Survos\FolioBundle\Service\FolioElasticRowIndex}).
 *
 * Dispatched when a build finishes without an FTS index. Route it to an async transport: a
 * page-level newspaper folio is a million rows, and indexing them is the work the FTS skip took
 * out of the build. Unrouted, Messenger handles it in the build's own process.
 */
final readonly class IndexFolioRowsMessage
{
    public function __construct(
        public string $folioCode,
    ) {
    }
}
