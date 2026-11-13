<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Path\DataPaths;
use Survos\FolioBundle\Service\DuckDbFolioConnection;
use Survos\FolioBundle\Service\DuckDbFolioMap;
use Survos\FolioBundle\Service\DuckDbFolioReader;
use Survos\FolioBundle\Service\LocalDuckDbFolioLocator;
use Survos\SearchBundle\Search\Url\DefaultUrlFormater;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class DuckDbFolioMapTest extends TestCase
{
    public function testCoordinatesAndCollectionFiltersUseOnlyDuckDb(): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Native PDO DuckDB required.');
        }
        $path = sys_get_temp_dir().'/folio-map-'.bin2hex(random_bytes(6)).'.dfolio';
        try {
            $db = new \PDO('duckdb:'.$path);
            $db->exec('CREATE TABLE item (id VARCHAR, local_id VARCHAR, core_id VARCHAR, dto_type VARCHAR, dto_data JSON)');
            $db->exec('CREATE TABLE schema_property (name VARCHAR, label VARCHAR, type VARCHAR, visible INTEGER, facet INTEGER, filterable INTEGER, position INTEGER)');
            $db->exec("INSERT INTO schema_property VALUES ('collections','Collections','array',1,1,1,0)");
            $insert = $db->prepare('INSERT INTO item VALUES (?, ?, ?, ?, ?)');
            foreach ([['a', 38, -78, ['Sperryville', 'Other']], ['b', 39, -77, ['Other']], ['c', null, -78, ['Sperryville']], ['d', 'invalid', -78, ['Sperryville']], ['e', 91, 0, ['Sperryville']], ['f', 0, 0, ['Zero']]] as [$id, $lat, $lng, $collections]) {
                $insert->execute([$id, $id, 'test/map:obj', 'object', json_encode(['title' => $id, 'latitude' => $lat, 'longitude' => $lng, 'collections' => $collections])]);
            }
            $insert = null;
            $db->exec('CHECKPOINT');
            $db = null;
            $map = new DuckDbFolioMap(new DuckDbFolioConnection(new LocalDuckDbFolioLocator(new DataPaths(dirname($path)), ['test/map' => $path]), new DuckDbFolioReader(), true), new DefaultUrlFormater($this->createStub(UrlGeneratorInterface::class)));
            self::assertTrue($map->hasCoordinates('test/map'));
            self::assertCount(3, $map->features('test/map', []));
            $features = $map->features('test/map', ['collections' => 'Sperryville', 'page' => 3, 'hitsPerPage' => 12]);
            self::assertCount(1, $features);
            self::assertSame('a', $features[0]['properties']['id']);
            self::assertSame([-78.0, 38.0], $features[0]['geometry']['coordinates']);
            self::assertCount(2, $map->features('test/map', ['collections' => 'Sperryville~~Other']));
            self::assertCount(0, $map->features('test/map', ['collections' => "Sperryville' OR 1=1 --"]));
            self::assertCount(0, $map->features('test/map', ['collections' => 'Sperryville'], '-77,38,-76,40'));
            self::assertCount(1, $map->features('test/map', ['collections' => 'Zero']));
            $db = new \PDO('duckdb:'.$path);
            $db->exec('DELETE FROM item');
            $db = null;
            self::assertFalse($map->hasCoordinates('test/map'));
            self::assertSame([], $map->features('test/map', []));
        } finally {
            $db = null;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
