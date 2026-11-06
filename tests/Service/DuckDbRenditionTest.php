<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\FolioBundle\Dto\DuckDbS3Configuration;
use Survos\FolioBundle\Service\DuckDbFolioExporter;
use Survos\FolioBundle\Service\DuckDbFolioReader;
use Survos\FolioBundle\Service\DuckDbRenditionReader;
use Survos\FolioBundle\Service\NewspaperGeometry;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class DuckDbRenditionTest extends TestCase
{
    private string $root;
    private ?Process $server = null;
    private array $original = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/rend-test-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        $source = new \PDO('sqlite:'.$this->root.'/source.folio');
        $source->exec("CREATE TABLE _folio_build(build_id TEXT, schema_version INT); INSERT INTO _folio_build VALUES ('source-a',1)");
        $source->exec('CREATE TABLE item(id TEXT PRIMARY KEY, local_id TEXT, core_id TEXT, dto_type TEXT, dto_data TEXT, extras TEXT, raw TEXT)');
        $source->exec('CREATE TABLE page(id TEXT PRIMARY KEY, row_id TEXT, seq INT, page_index INT, width INT, height INT, url TEXT, source_url TEXT, layout TEXT, ledger TEXT, dialogue TEXT, text TEXT)');
        $source->exec('CREATE TABLE link(left_id TEXT, left_core TEXT, right_id TEXT, right_core TEXT)');
        $insert = $source->prepare('INSERT INTO item VALUES (?,?,?,?,?,?,?)');
        foreach (['one', 'two'] as $issue) {
            $insert->execute(['news/test:doc:'.$issue, $issue, 'news/test:doc', 'newspaper', json_encode(['id' => $issue, 'title' => '新聞 '.$issue]), json_encode(['pageAssets' => [['pageIndex' => 0, 'width' => 1000, 'height' => 2000]], 'textsheetGrouping' => ['pages' => [['analysis' => ['columnCenters' => [12, 30], 'mastheadRegion' => [0, 0, 100, 20]], 'groups' => [['blockIds' => ['B1']]]]]]]), null]);
            $insert->execute(['news/test:article:'.$issue.'-a', $issue.'-a', 'news/test:article', 'newspaper', json_encode(['id' => $issue.'-a', 'ocrText' => 'Exact body text']), json_encode(['issueId' => $issue, 'segments' => [['id' => 's1', 'anchors' => [['page' => 0, 'sourceBlockId' => 'B1', 'box' => [10, 20, 30, 40]]]]]]), null]);
            $source->prepare('INSERT INTO link VALUES (?,?,?,?)')->execute([$issue.'-a', 'article', $issue, 'doc']);
            $source->prepare('INSERT INTO page VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute(['news/test:doc:'.$issue.'#1', 'news/test:doc:'.$issue, 1, 0, 1000, 2000, 'https://images.test/'.$issue, null, json_encode(['blocks' => [['id' => 'B1', 'bbox' => ['x' => 10, 'y' => 20, 'width' => 30, 'height' => 40], 'text' => 'Exact source', 'lines' => [['bbox' => [10, 20, 30, 10], 'words' => [['text' => 'Exact', 'bbox' => [10, 20, 10, 10]]]]]]]]), null, null, 'Exact source']);
        }
        foreach (['item', 'page'] as $table) {
            $this->original[$table] = $source->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        }
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        (new Filesystem())->remove($this->root);
    }

    private function export(): string
    {
        mkdir($this->root.'/bucket/dfolio/news/test', 0777, true);
        $target = $this->root.'/bucket/dfolio/news/test/source.dfolio';
        (new DuckDbFolioExporter())->export($this->root.'/source.folio', $target, function (string $path): array {
            $key = '/bucket/dfolio/news/test/source.drend';
            copy($path, $this->root.$key);

            return ['uri' => 's3:/'.$key, 'bytes' => filesize($path)];
        });

        return $target;
    }

    public function testRefusesEmbeddedNewspaperGeometry(): void
    {
        $this->expectExceptionMessage('rendition publisher');
        (new DuckDbFolioExporter())->export($this->root.'/source.folio', $this->root.'/unsafe.dfolio');
    }

    public function testSplitRestoresEveryOriginalValueWithoutGeometryInDfolio(): void
    {
        $target = $this->export();
        $comparison = (new \Survos\FolioBundle\Service\DuckDbFolioComparator())->compare($this->root.'/source.folio', $target,
            fn (array $ref): \PDO => (new DuckDbRenditionReader(new DuckDbFolioReader()))->scope((new DuckDbFolioReader())->openRend($this->root.substr($ref['uri'], 4)), $ref['issue_id'], $ref['build_id']));
        self::assertTrue($comparison['matched']);
        self::assertSame(2, $comparison['renditionsCompared']);
        $db = (new DuckDbFolioReader())->open($target);
        self::assertSame(2, $db->query('SELECT count(*) FROM _renditions')->fetchColumn());
        self::assertSame(0, $db->query('SELECT count(*) FROM page WHERE layout IS NOT NULL OR width IS NOT NULL OR height IS NOT NULL')->fetchColumn());
        foreach ($db->query('SELECT * FROM item', \PDO::FETCH_ASSOC) as $row) {
            self::assertSame([], NewspaperGeometry::splitRow('item', $row)[1]);
        }
        $reader = new DuckDbRenditionReader(new DuckDbFolioReader());
        foreach (['one', 'two'] as $issue) {
            $ref = $reader->reference($db, 's3://bucket/dfolio/news/test/source.dfolio', $issue);
            $rend = $reader->scope((new DuckDbFolioReader())->openRend($this->root.substr($ref['uri'], 4)), $issue, $ref['build_id']);
            $items = array_values(array_filter($db->query('SELECT * FROM item ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC), fn ($r) => str_contains($r['id'], $issue)));
            $pages = array_values(array_filter($db->query('SELECT * FROM page ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC), fn ($r) => str_contains($r['id'], $issue)));
            [$items,$pages] = $reader->restore($rend, $items, $pages);
            foreach (['item' => $items, 'page' => $pages] as $table => $rows) {
                foreach ($rows as $row) {
                    $original = array_values(array_filter($this->original[$table], fn ($r) => $r['id'] === $row['id']))[0];
                    foreach (['dto_data', 'extras', 'raw', 'layout'] as $field) {
                        if (isset($row[$field])) {
                            $row[$field] = json_decode($row[$field], true);
                            $original[$field] = json_decode($original[$field], true);
                        }
                    }
                    self::assertEquals($original, $row);
                }
            }
            self::assertSame('B1', $rend->query('SELECT substr(id,3) FROM block')->fetchColumn());
        }
    }

    public function testSavedGroupingIsExportedAsTypedTablesSortedByIssue(): void
    {
        $db = (new DuckDbFolioReader())->open($this->export());
        self::assertSame(2, (int) $db->query('SELECT count(*) FROM issue_page')->fetchColumn());
        self::assertSame(2, (int) $db->query('SELECT count(*) FROM issue_grouping')->fetchColumn());
        self::assertSame(2, (int) $db->query('SELECT count(*) FROM issue_extras')->fetchColumn());
        // The issue's other extras are kept apart from the grouping, so a reader never has to open item.extras.
        $extras = $db->query("SELECT extras FROM issue_extras WHERE issue_id = 'one'")->fetchColumn(); // the driver decodes JSON columns
        self::assertArrayHasKey('pageAssets', $extras);
        self::assertArrayNotHasKey('textsheetGrouping', $extras);
        $rows = $db->query('SELECT issue_id, page_index, ord, block_ids FROM issue_group ORDER BY issue_id, page_index, ord', \PDO::FETCH_ASSOC)->fetchAll();
        self::assertSame(['one', 'two'], array_column($rows, 'issue_id'));
        self::assertSame([1, 1], array_map('intval', array_column($rows, 'ord')));
        // The JSON stays where it was, so existing readers and the comparator keep working.
        self::assertSame(2, (int) $db->query("SELECT count(*) FROM item WHERE extras LIKE '%textsheetGrouping%'")->fetchColumn());
    }

    public function testBothFilesAreReadRemotelyAndMissingOrWrongRendFails(): void
    {
        $this->export();
        file_put_contents($this->root.'/router.php', <<<'ROUTER'
<?php
$file=__DIR__.parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (!is_file($file)) { http_response_code(404); exit; }
$size=filesize($file); $start=0; $end=$size-1;
$range=$_SERVER['HTTP_RANGE']??null;
if ($range!==null) { preg_match('/bytes=(\d+)-(\d*)/',$range,$m);$start=(int)$m[1];$end=$m[2]===''?$end:min($end,(int)$m[2]);http_response_code(206);header("Content-Range: bytes $start-$end/$size"); }
$length=$end-$start+1;
header('Accept-Ranges: bytes'); header('ETag: "fixture"'); header('Content-Length: '.$length);
file_put_contents(__DIR__.'/requests.jsonl',json_encode(['path'=>$_SERVER['REQUEST_URI'],'range'=>$range,'signed'=>str_starts_with($_SERVER['HTTP_AUTHORIZATION']??'','AWS4-HMAC-SHA256 ')])."\n",FILE_APPEND);
if ($_SERVER['REQUEST_METHOD']!=='HEAD') {$f=fopen($file,'rb');fseek($f,$start);echo fread($f,$length);fclose($f);}
ROUTER);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->server = new Process([PHP_BINARY, '-S', $address, $this->root.'/router.php'], $this->root);
        $this->server->start();
        self::assertTrue($this->server->waitUntil(fn ($type, $out) => str_contains($out, 'Development Server')));
        $config = new DuckDbS3Configuration('http://'.$address, 'fixture-key', 'fixture-secret', 'us-east-1');
        $uri = 's3://bucket/dfolio/news/test/source.dfolio';
        $db = (new DuckDbFolioReader())->open($uri, $config);
        $reader = new DuckDbRenditionReader(new DuckDbFolioReader());
        $rend = $reader->open($db, $uri, 'one', $config);
        self::assertSame(1000, $rend->query('SELECT width FROM page')->fetchColumn());
        $requests = array_map(fn ($s) => json_decode($s, true), file($this->root.'/requests.jsonl', FILE_IGNORE_NEW_LINES));
        foreach (['.dfolio', '.drend'] as $extension) {
            self::assertTrue(array_any($requests, fn ($r) => str_ends_with($r['path'], $extension) && null !== $r['range'] && $r['signed']));
        }
        try {
            try {
                $rend->exec('DELETE FROM page');
                self::fail('The issue view must not be writable');
            } catch (\PDOException) {
            }
            $rend->exec('DELETE FROM folio.main.page');
            self::fail('Remote rend must be read-only');
        } catch (\PDOException $e) {
            self::assertStringContainsString('read-only', $e->getMessage());
        }
        $this->expectExceptionMessage('Missing or mismatched');
        $reader->open($db, $uri, 'absent', $config);
    }

    public function testOrphanArticleCannotBeSilentlyPublishedWithoutGeometry(): void
    {
        $source = new \PDO('sqlite:'.$this->root.'/source.folio');
        $source->exec("DELETE FROM link WHERE right_id = 'two'");
        $source = null;
        $this->expectExceptionMessage('Every newspaper row and page');
        $this->export();
    }

    public function testPairComparisonDetectsAlteredGeometry(): void
    {
        $target = $this->export();
        $db = new \PDO('duckdb:'.$target);
        $uri = $db->query("SELECT uri FROM _renditions WHERE issue_id = 'one'")->fetchColumn();
        $db = null;
        $rend = new \PDO('duckdb:'.$this->root.substr($uri, 4));
        $patch = json_decode($rend->query("SELECT patch FROM page_geometry WHERE issue = 'one'")->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);
        $patch['width'] = 9999;
        $rend->prepare("UPDATE page_geometry SET patch = ? WHERE issue = 'one'")->execute([json_encode($patch, JSON_THROW_ON_ERROR)]);
        $rend = null;
        $this->expectExceptionMessage('Content mismatch in page');
        (new \Survos\FolioBundle\Service\DuckDbFolioComparator())->compare($this->root.'/source.folio', $target,
            fn (array $ref): \PDO => (new DuckDbRenditionReader(new DuckDbFolioReader()))->scope((new DuckDbFolioReader())->openRend($this->root.substr($ref['uri'], 4)), $ref['issue_id'], $ref['build_id']));
    }

    public function testFailedRendPublicationPreservesPreviousDfolio(): void
    {
        $path = $this->root.'/existing.dfolio';
        file_put_contents($path, 'previous');
        try {
            (new DuckDbFolioExporter())->export($this->root.'/source.folio', $path, static function (): never {throw new \RuntimeException('upload failed'); });
            self::fail();
        } catch (\RuntimeException $e) {
            self::assertSame('upload failed', $e->getMessage());
            self::assertSame('previous', file_get_contents($path));
        }
    }
}
