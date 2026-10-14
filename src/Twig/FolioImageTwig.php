<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Twig;

use Survos\ImageContracts\ImageUrlBuilderInterface;
use Twig\Attribute\AsTwigFilter;

/**
 * `folio_image`: every image URL in this bundle's templates goes through here, so an image backend
 * is an enhancement and not a hidden requirement. With survos/imgproxy-bundle or
 * survos/imagor-bundle installed it is that backend's preset URL (`|imgproxy(preset)` /
 * `|imagor(preset)`); without either the source URL is returned as-is, and the page still renders.
 */
final readonly class FolioImageTwig
{
    public function __construct(private ?ImageUrlBuilderInterface $imageUrls = null)
    {
    }

    #[AsTwigFilter('folio_image')]
    public function image(?string $url, string $preset = 'thumb', ?string $format = null): string
    {
        if (null === $url || '' === $url) {
            return '';
        }

        // A source the backend can't fetch (imagor outside its buckets, a relative path) renders
        // as-is rather than as a URL that is certain to fail.
        return null !== $this->imageUrls && $this->imageUrls->supports($url) ? $this->imageUrls->preset($url, $preset, $format) : $url;
    }
}
