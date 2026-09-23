<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Twig;

use Twig\Attribute\AsTwigFilter;

/**
 * `folio_image`: every image URL in this bundle's templates goes through here, so imgproxy is an
 * enhancement and not a hidden requirement. With survos/imgproxy-bundle installed it is exactly
 * `|imgproxy(preset)`; without it the source URL is returned as-is, and the page still renders.
 *
 * The builder is typed `?object` on purpose: naming ImgproxyUrlBuilder in the signature would make
 * this class unloadable in an app without the imgproxy bundle.
 */
final readonly class FolioImageTwig
{
    public function __construct(private ?object $imgproxy = null) {}

    #[AsTwigFilter('folio_image')]
    public function image(?string $url, string $preset = 'thumb', ?string $format = null): string
    {
        if ($url === null || $url === '') {
            return '';
        }

        return $this->imgproxy?->resizePreset($url, $preset, $format) ?? $url;
    }
}
