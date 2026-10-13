<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Message;

/**
 * Index one folio's rows into the shared Elasticsearch search indexes and verify them
 * ({@see \Survos\FolioBundle\Service\FolioSearchIndex}).
 *
 * Dispatched by an opted-in producer after a working Folio is atomically published. Route it to an async transport: a
 * page-level newspaper folio is a million rows. Unrouted, Messenger handles it in the build's own process.
 */
final readonly class IndexFolioRowsMessage
{
    public function __construct(
        public string $folioCode,
    ) {
    }
}
