<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Configuration\FolioSearchConfiguration;

final class FolioSearchConfigurationTest extends TestCase
{
    public function testExistingMetadataRetainsSqliteSearch(): void
    {
        $config = FolioSearchConfiguration::fromExtras(['ftsContent' => 'none']);
        self::assertSame('sqlite', $config->backend);
        self::assertFalse($config->allowFtsSkip);
    }

    public function testElasticsearchDoesNotImplicitlyAllowSkipping(): void
    {
        self::assertFalse(FolioSearchConfiguration::fromExtras(['search' => ['backend' => 'elasticsearch']])->allowFtsSkip);
    }

    public function testExplicitPermissionRoundTrips(): void
    {
        $search = ['backend' => 'elasticsearch', 'allowFtsSkip' => true];
        self::assertSame($search, FolioSearchConfiguration::fromExtras(['search' => $search])->toArray());
    }

    #[DataProvider('invalidSearch')]
    public function testInvalidPolicyIsRejected(mixed $search): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FolioSearchConfiguration::fromExtras(['search' => $search]);
    }

    public static function invalidSearch(): iterable
    {
        yield [['allowFtsSkip' => true]];
        yield [['backend' => 'unknown']];
        yield [['backend' => 'elasticsearch', 'allowFtsSkip' => 'false']];
        yield [['backend' => false]];
        yield [['allowFtsSkipp' => true]];
        yield ['elasticsearch'];
    }
}
