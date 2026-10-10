<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Util\ImageUrl;

/**
 * The imgproxy SOURCE for the small presets (tiny/thumb/observe/display/card). 'archive' and
 * downloads keep using the page url — the original.
 *
 * Imagery normally comes from pages only, and dto_data.thumbnailUrl is ignored as stale provenance
 * (see Row::getRawThumbnailSource(); mus/rijk's page.url is the s3:// mirror while its dto still
 * points at the old public URL). The one case it is NOT stale: the page is still the harvested
 * original (page url == dto largeImageUrl) and the provider declared a different, smaller rendition
 * of the same image as thumbnailUrl. mus/fpus is the worked example: Kronofoto's /media/original/
 * is ~9 MB at ~400 KB/s (22-27 s per uncached thumb) while /media/h700/ is ~26 KB in 0.27 s.
 * Once a page is mirrored its url no longer equals largeImageUrl, so the mirror wins again.
 */
final class DisplayImageSource
{
    /** @param array<string,mixed> $dto */
    public static function pick(?string $pageUrl, array $dto): ?string
    {
        if ($pageUrl === null || $pageUrl === '') {
            return null;
        }
        $thumbnail = $dto['thumbnailUrl'] ?? null;
        if (!is_string($thumbnail) || $thumbnail === '' || $thumbnail === $pageUrl
            || ($dto['largeImageUrl'] ?? null) !== $pageUrl
            || !ImageUrl::classify($thumbnail)->isRenderable()) {
            return $pageUrl;
        }

        return $thumbnail;
    }
}
