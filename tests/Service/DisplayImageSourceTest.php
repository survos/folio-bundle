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
        $dto = ['largeImageUrl' => self::ORIGINAL, 'thumbnailUrl' => self::H700];
        self::assertSame(self::H700, DisplayImageSource::pick(self::ORIGINAL, $dto));
    }

    public function testMirroredPageBeatsStaleDtoThumbnail(): void
    {
        // mus/rijk: the page is the s3:// mirror, dto_data still holds the old public URL.
        $dto = ['largeImageUrl' => 'https://old.example/a.jpg', 'thumbnailUrl' => 'https://old.example/a-small.jpg'];
        self::assertSame('s3://museado/orig/a.jpg', DisplayImageSource::pick('s3://museado/orig/a.jpg', $dto));
    }

    public function testNoPageMeansNoImageAndSameUrlIsThePage(): void
    {
        self::assertNull(DisplayImageSource::pick(null, ['thumbnailUrl' => self::H700]));
        self::assertSame(self::ORIGINAL, DisplayImageSource::pick(self::ORIGINAL, ['largeImageUrl' => self::ORIGINAL, 'thumbnailUrl' => self::ORIGINAL]));
    }
}
