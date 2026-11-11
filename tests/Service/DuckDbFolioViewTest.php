<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Path\DataPaths;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\FolioBundle\Service\DuckDbFolioConnection;
use Survos\FolioBundle\Service\DuckDbFolioReader;
use Survos\FolioBundle\Service\DuckDbFolioView;
use Survos\FolioBundle\Service\FolioDtoTypeResolver;
use Survos\FolioBundle\Service\LocalDuckDbFolioLocator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DuckDbFolioViewTest extends TestCase
{
    public static function storageTypes(): iterable
    {
        yield 'exported text' => ['VARCHAR'];
        yield 'native JSON' => ['JSON'];
    }

    #[DataProvider('storageTypes')]
    public function testPresentationModelRequiresOnlyTheDuckDbManifestation(string $storageType): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Native PDO DuckDB required.');
        }
        $root = sys_get_temp_dir().'/folio-duck-view-'.bin2hex(random_bytes(6));
        $fs = new Filesystem();
        $fs->mkdir($root);
        try {
            $path = $root.'/snapshot.dfolio';
            $db = new \PDO('duckdb:'.$path);
            $db->exec("CREATE TABLE item (id VARCHAR, core_id VARCHAR, local_id VARCHAR, label VARCHAR, dto_type VARCHAR, dto_data $storageType, extras $storageType, raw $storageType)");
            $db->prepare('INSERT INTO item VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                'test/example:obj:42', 'test/example:obj', '42', 'Landscape', 'painting',
                json_encode([ItemField::ID => '42', 'emptyObject' => (object) [], 'emptyList' => [], ItemField::TITLE => 'Landscape', ItemField::URL => 'https://example.org/42']), null, null,
            ]);
            $db->exec('CREATE TABLE page (id VARCHAR, row_id VARCHAR, seq INTEGER, url VARCHAR, source_url VARCHAR, width INTEGER, height INTEGER, page_index INTEGER, type VARCHAR, media_id VARCHAR, text VARCHAR, htr VARCHAR, dense_summary VARCHAR, thumb_hash VARCHAR, color VARCHAR, source_checksum VARCHAR, layout VARCHAR, ledger VARCHAR, dialogue VARCHAR)');
            $db->exec("INSERT INTO page VALUES ('test/example:obj:42#1','test/example:obj:42',1,'https://example.org/image.jpg',NULL,900,600,0,'photo',NULL,'FIRST SIDE',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL)");
            $layout = [['text' => 'SECOND SIDE', 'type' => 'paragraph', 'bbox' => ['x' => 10, 'y' => 20, 'width' => 300, 'height' => 40]]];
            $db->prepare("INSERT INTO page VALUES ('test/example:obj:42#2','test/example:obj:42',2,'https://example.org/back.jpg',NULL,900,600,0,'photo',NULL,'SECOND SIDE',NULL,NULL,NULL,NULL,NULL,?,NULL,NULL)")->execute([json_encode($layout)]);
            $db->exec('CHECKPOINT');
            $db = null;
            $locator = new LocalDuckDbFolioLocator(new DataPaths($root), ['test/example' => $path]);
            $connection = new DuckDbFolioConnection($locator, new DuckDbFolioReader(), true);
            $view = new DuckDbFolioView($connection, new FolioDtoTypeResolver());
            $data = $view->item('test/example', 'obj', '42');
            self::assertSame('Landscape', $data['row']->displayTitle());
            self::assertSame('test/example', $data['row']->getFolioCode());
            self::assertSame('https://example.org/42', $data['dto']->url);
            self::assertStringContainsString('"emptyObject":{}', $data['dtoJson']);
            self::assertStringContainsString('"emptyList":[]', $data['dtoJson']);
            self::assertSame(900, $data['pages'][0]['width']);
            self::assertCount(2, $data['row']->pages);
            self::assertSame('FIRST SIDE', $data['row']->pages[0]->text);
            self::assertSame($layout, $data['row']->pages[1]->layout);
            self::assertSame($layout, $data['pages'][1]['layout']);
            self::assertTrue($data['pageTableExists']);
            self::assertFileDoesNotExist($root.'/folio/test/example.folio');
            try {
                $view->item('test/example', 'obj', "42' OR 1=1 --");
                self::fail('Bound item scope must reject unknown records.');
            } catch (NotFoundHttpException) {
            }
            try {
                (new DuckDbFolioConnection($locator, new DuckDbFolioReader()))->connect(['folioCode' => 'test/example']);
                self::fail('Browsing must be opt-in.');
            } catch (NotFoundHttpException) {
            }
            $readOnly = $connection->connect(['folioCode' => 'test/example']);
            $this->expectException(\PDOException::class);
            $readOnly->exec('DELETE FROM item');
        } finally {
            $readOnly = null;
            $fs->remove($root);
        }
    }

    public function testTraversalIsRejectedBeforeResolvingAnyPath(): void
    {
        $this->expectException(NotFoundHttpException::class);
        (new LocalDuckDbFolioLocator(new DataPaths('/unused')))->locate('../private');
    }
}
