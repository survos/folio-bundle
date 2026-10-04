<?php

declare(strict_types=1);
namespace Survos\FolioBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Survos\Folio\CatalogMetadata;

final class CatalogMetadataTest extends TestCase
{
    public function testPropertiesOverrideRegistryAndPaperSearchIncludesEssay(): void
    {
        $entry = CatalogMetadata::project(['label' => 'Corrected', 'description' => null, 'tags' => ['newspaper'], 'title.essay' => 'The abolitionist editor', 'rowCount' => 200],
            ['datasetKey' => 'loc/paper', 'title' => 'Old', 'description' => 'Stale', 'tags' => ['featured'], 'rowCount' => 1]);
        self::assertSame('Corrected', $entry['title']); self::assertNull($entry['description']);
        self::assertSame(['newspaper', 'featured'], $entry['tags']); self::assertSame(200, $entry['rowCount']);
        self::assertTrue(CatalogMetadata::matches($entry, 'ABOLITIONIST'));
        self::assertFalse(CatalogMetadata::matches($entry, 'Stale'));
        self::assertFalse(CatalogMetadata::matches($entry, 'essay'), 'property names are not search text');
    }
}
