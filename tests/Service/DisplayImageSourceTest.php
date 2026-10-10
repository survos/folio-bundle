<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Service\DisplayImageSource;

final class DisplayImageSourceTest extends TestCase
{
    private const ORIGINAL = 'https://fortepan.us/media/original/6f49d4ba.jpg';
    private const H700 = 'https://fortepan.us/media/h700/6f49d4ba.jpg';

    public function testDeclaredRenditionWinsWhilePageIsTheHarvestedOriginal(): void
    {
        $dto = ['largeImageUrl' => self::ORIGINAL, 'displayImageUrl' => self::H700];
        self::assertSame(self::H700, DisplayImageSource::pick(self::ORIGINAL, $dto));
    }

    public function testS3MirrorPageBeatsDisplayRendition(): void
    {
        $dto = ['displayImageUrl' => self::H700];
        self::assertSame('s3://museado/orig/a.jpg', DisplayImageSource::pick('s3://museado/orig/a.jpg', $dto));
    }

    public function testEnrichedLargeImageUrlDoesNotDisableTheRendition(): void
    {
        // prod: enrich points largeImageUrl at mediary's archived copy; the page stays the original.
        $dto = ['largeImageUrl' => 'https://fsn1.your-objectstorage.com/museado/orig/f7/47/f747.jpg', 'displayImageUrl' => self::H700];
        self::assertSame(self::H700, DisplayImageSource::pick(self::ORIGINAL, $dto));
    }

    public function testSmallThumbnailUrlIsNotADisplaySource(): void
    {
        $dto = ['largeImageUrl' => self::ORIGINAL, 'thumbnailUrl' => 'https://ids.si.edu/ids/download?id=X_thumb'];
        self::assertSame(self::ORIGINAL, DisplayImageSource::pick(self::ORIGINAL, $dto));
    }

    public function testNoPageMeansNoImageAndSameUrlIsThePage(): void
    {
        self::assertNull(DisplayImageSource::pick(null, ['displayImageUrl' => self::H700]));
        self::assertSame(self::ORIGINAL, DisplayImageSource::pick(self::ORIGINAL, ['largeImageUrl' => self::ORIGINAL, 'displayImageUrl' => self::ORIGINAL]));
    }
}
