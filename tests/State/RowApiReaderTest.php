<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\State;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\HttpOperation;
use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Entity\Row;
use Survos\FolioBundle\State\FolioRowProvider;

final class RowApiReaderTest extends TestCase
{
    /**
     * FolioRouteAttributeListener opens the SQLite folio for any route with a folioCode, unless the route says
     * it is read from the dfolio. An operation served by FolioRowProvider must say so, or the API answers
     * "Folio not found" on a host that has no .folio file.
     */
    public function testOperationsServedFromTheDfolioDoNotOpenASqliteFolio(): void
    {
        $resource = (new \ReflectionClass(Row::class))->getAttributes(ApiResource::class)[0]->newInstance();
        $checked = 0;
        foreach ($resource->getOperations() ?? [] as $operation) {
            if (!$operation instanceof HttpOperation || FolioRowProvider::class !== $operation->getProvider()) {
                continue;
            }
            self::assertSame('duckdb', $operation->getDefaults()['_folio_reader'] ?? null, (string) $operation->getUriTemplate());
            ++$checked;
        }
        self::assertSame(2, $checked);
    }
}
