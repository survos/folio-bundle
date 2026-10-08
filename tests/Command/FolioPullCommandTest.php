<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Command\FolioPullCommand;

final class FolioPullCommandTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?string}> */
    public static function stems(): iterable
    {
        yield 'base build' => ['fpeu/italy', 'fpeu/italy', null];
        yield 'locale variant' => ['fpeu/italy.en', 'fpeu/italy', 'en'];
        yield 'region locale' => ['mus/jarc.pt-BR', 'mus/jarc', 'pt-BR'];
        yield 'bare code' => ['jarc', 'jarc', null];
        yield 'dot only in directory' => ['my.provider/jarc', 'my.provider/jarc', null];
    }

    #[DataProvider('stems')]
    public function testSplitLocale(string $stem, string $code, ?string $locale): void
    {
        self::assertSame([$code, $locale], FolioPullCommand::splitLocale($stem));
    }
}
