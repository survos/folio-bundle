<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/**
 * Where a published SQLite folio can be read in place over HTTP range requests (sqlite_web_vfs), instead of from the
 * local folio root. FolioService::switch() asks it first; null means "open the local file as usual".
 */
interface FolioRemoteSourceInterface
{
    /**
     * @return ?array{url: string, dbiUrl: ?string} an http(s) URL the VFS can GET with Range headers (a presigned S3 URL
     *                                              works), and the optional URL of the .dbi helper beside it
     */
    public function locate(string $folioCode, ?string $locale = null): ?array;
}
