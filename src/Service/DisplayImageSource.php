<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Util\ImageUrl;
use Survos\DataContracts\Vocabulary\ItemField;

/**
 * The imgproxy SOURCE for the small presets (tiny/thumb/observe/display/card). 'archive' and
 * downloads keep using the page url — the original.
 *
 * Imagery normally comes from pages only; dto_data image fields are ignored as stale provenance
 * (see Row::getRawThumbnailSource(); mus/rijk's page.url is the s3:// mirror while its dto still
 * points at the old public URL). The exception is opt-in: a provider that declares
 * displayImageUrl (ItemField::DISPLAY_IMAGE_URL), a rendition big enough for every display preset,
 * while the page is still the harvested original (page url == dto largeImageUrl). mus/fpus is the
 * worked example: Kronofoto's /media/original/ is ~9 MB at ~400 KB/s (22-27 s per uncached thumb)
 * while /media/h700/ is ~26 KB in 0.27 s. Once a page is mirrored, the mirror wins again.
 *
 * Not thumbnailUrl: for smith (*_thumb), dc (image_thumbnail_300), chicago (full/200,) and others
 * it is a 200-300px thumbnail, and 'display' would upscale it.
 */
final class DisplayImageSource
{
    /** @param array<string,mixed> $dto */
    public static function pick(?string $pageUrl, array $dto): ?string
    {
        if ($pageUrl === null || $pageUrl === '') {
            return null;
        }
        $display = $dto[ItemField::DISPLAY_IMAGE_URL] ?? null;
        if (!is_string($display) || $display === '' || $display === $pageUrl
            || ($dto['largeImageUrl'] ?? null) !== $pageUrl
            || !ImageUrl::classify($display)->isRenderable()) {
            return $pageUrl;
        }

        return $display;
    }
}
