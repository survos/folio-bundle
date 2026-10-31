<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Service;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Path\DataPaths;
use Survos\FolioBundle\Service\FolioElasticClient;
use Survos\FolioBundle\Service\FolioFtsIndexer;
use Survos\FolioBundle\Service\FolioSchemaManager;
use Survos\FolioBundle\Service\FolioSearchIndex;
use Survos\FolioBundle\Service\FolioSearchProjector;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FolioSearchIndexTest extends TestCase
{
    private string $root;
    private FolioService $folios;
    /** @var array<string, array<string, array<string, mixed>>> index => id => document */
    private array $docs = [];
    /** @var array<string, array<string, mixed>> */
    private array $mappings = [];
    private bool $dropOnBulk = false;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/folio-search-test-'.bin2hex(random_bytes(6));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getEventManager')->willReturn(new EventManager());
        $this->folios = new FolioService($em, new FolioSchemaManager(new ArrayAdapter()), new DataPaths($this->root));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testEveryRowLandsOnceInItsFamilyWithLinksPagesAndFacets(): void
    {
        $this->newspaper('test/paper');
        $result = $this->index()->index('test/paper');

        self::assertSame(['item' => 1, 'text' => 2], ['item' => $result['item'], 'text' => $result['text']]);
        self::assertSame('ok', $result['verify']['status']);
        self::assertCount(1, $this->docs['folio_item']);
        self::assertCount(2, $this->docs['folio_text']);

        $article = $this->docs['folio_text']['test/paper:article:i1-a1'];
        self::assertSame('test/paper:doc:i1', $article['parentRowId']);
        self::assertSame(['test/paper:doc:i1'], $article['links']);
        self::assertSame('1953-01-01', $article['dateStart']);
        self::assertSame(1953, $article['year']);
        self::assertSame(['scan'], $article['mediaKinds']);
        self::assertSame(2, $article['pageCount']);
        self::assertSame('s3://bucket/i1-a1-1.jpg', $article['thumbnailUrl'], 'the first page by seq, not by insertion');
        self::assertSame('advertisement', $article['recordKind']);
        self::assertSame('Virginia', $article['attrs']['state']);
        self::assertSame('CC0', $article['license']);
        self::assertStringContainsString('tractor', $article['body']);

        $issue = $this->docs['folio_item']['test/paper:doc:i1'];
        self::assertSame('record', $issue['role']);
        self::assertArrayNotHasKey('parentRowId', $issue);

        $meta = $this->docs['folio_meta']['test/paper'];
        self::assertTrue($meta['complete']);
        self::assertSame(3, $meta['rows']);
    }

    public function testDuckDbProducesIdenticalDocumentsWithoutTheSqliteFile(): void
    {
        if (!in_array('duckdb', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('Requires pdo_duckdb.');
        }
        $this->newspaper('test/paper');
        $path = $this->folios->path('test/paper');
        $sqlite = new \PDO('sqlite:'.$path);
        $sqlite->exec('CREATE TABLE _folio_build (build_id TEXT, schema_version INT)');
        $sqlite->exec("INSERT INTO _folio_build VALUES ('shared-build', 1)");
        $sqlite->exec('CREATE TABLE schema_table (id TEXT, core_code TEXT, dto_type TEXT)');
        $sqlite->exec("INSERT INTO schema_table VALUES ('x', 'article', 'document')");
        $sqlite->exec("UPDATE page SET type = 'photo' WHERE seq = 2");
        $sqlite->exec("INSERT INTO link VALUES ('article', 'i1-a1', 'doc', 'z'), ('article', 'i1-a1', 'doc', 'a')");
        (new \Survos\FolioBundle\Service\DuckDbFolioExporter())->export($path, substr($path, 0, -5).'duckdb');
        $sqlite = null;
        $expectedBuild = $this->index()->buildStamp('test/paper');
        $this->index()->index('test/paper');
        $expected = [$this->docs['folio_item'], $this->docs['folio_text']];
        $expectedFacets = $this->docs['folio_meta']['test/paper']['facets'];
        unlink($path);
        $duck = $this->index('duckdb');
        self::assertSame($expectedBuild, $duck->buildStamp('test/paper'));
        self::assertSame('ok', $duck->index('test/paper')['verify']['status']);
        self::assertSame($expected, [$this->docs['folio_item'], $this->docs['folio_text']]);
        self::assertSame('ok', $duck->verify('test/paper')['status']);
        self::assertSame($expectedFacets, $this->docs['folio_meta']['test/paper']['facets']);
        self::assertFileDoesNotExist($path);
        $snapshot = new \PDO('duckdb:'.substr($path, 0, -5).'duckdb');
        $snapshot->exec("UPDATE _folio_build SET build_id = 'next-build'");
        $snapshot = null;
        self::assertSame('stale', $duck->verify('test/paper')['status']);
        self::assertSame('ok', $duck->index('test/paper')['verify']['status']);
        $snapshot = new \PDO('duckdb:'.substr($path, 0, -5).'duckdb');
        $snapshot->exec('UPDATE _folio_build SET schema_version = 999');
        $snapshot = null;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid or unsupported Folio build identity');
        $duck->index('test/paper');
    }

    public function testDuckDbSourceDoesNotFallBackToSqlite(): void
    {
        $this->newspaper('test/paper');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No published DuckDB');
        $this->index('duckdb')->index('test/paper');
    }

    public function testImageCriteriaExcludeAudioAndBlankPagesAndKeepCanonicalTags(): void
    {
        $this->newspaper('test/paper');
        $db = new \PDO('sqlite:'.$this->folios->path('test/paper'));
        $db->exec("UPDATE page SET type = 'audio' WHERE seq = 2");
        $db->exec("UPDATE page SET url = ' ' WHERE row_id = 'test/paper:article:i1-a2'");
        $db->exec(<<<'SQL'
            UPDATE item SET dto_data = '{"tags":["ai:describe","curated"]}', extras = '{"source_tags":["stale"]}' WHERE local_id = 'i1-a1'
            SQL);
        $this->index()->index('test/paper');
        $first = $this->docs['folio_text']['test/paper:article:i1-a1'];
        self::assertSame(2, $first['pageCount']);
        self::assertSame(1, $first['imageCount']);
        self::assertSame(['ai:describe', 'curated'], $first['attrs']['tags']);
        self::assertSame(0, $this->docs['folio_text']['test/paper:article:i1-a2']['imageCount']);
    }

    public function testRebuildReplacesInPlaceAndDropsVanishedRows(): void
    {
        $this->newspaper('test/paper');
        $index = $this->index();
        $index->index('test/paper');

        $db = new \PDO('sqlite:'.$this->folios->path('test/paper'));
        $db->exec("DELETE FROM item WHERE id = 'test/paper:article:i1-a2'");
        touch($this->folios->path('test/paper'), time() + 10); // a new publication

        $result = $index->index('test/paper');
        self::assertSame(1, $result['removed']);
        self::assertSame(['test/paper:article:i1-a1'], array_keys($this->docs['folio_text']));
        self::assertSame('ok', $index->verify('test/paper')['status']);
    }

    public function testVerifyReportsAPartialIndexAndIndexingRefusesToMarkItComplete(): void
    {
        $this->newspaper('test/paper');
        $this->dropOnBulk = true;
        try {
            $this->index()->index('test/paper');
            self::fail('A partial index must not verify.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('did not verify', $e->getMessage());
        }
        self::assertFalse($this->docs['folio_meta']['test/paper']['complete']);
        self::assertSame('partial', $this->index()->verify('test/paper')['status']);
    }

    public function testAnEmptyFolioVerifiesOnceIndexed(): void
    {
        $this->newspaper('test/empty');
        (new \PDO('sqlite:'.$this->folios->path('test/empty')))->exec('DELETE FROM item');
        $index = $this->index();
        self::assertSame('missing', $index->verify('test/empty')['status']);
        self::assertSame('ok', $index->index('test/empty')['verify']['status']);
    }

    public function testAFolioIndexedByAnOlderProjectionIsOutdated(): void
    {
        $this->newspaper('test/paper');
        $index = $this->index();
        $index->index('test/paper');
        $this->docs['folio_meta']['test/paper']['documentVersion'] = FolioSearchProjector::DOCUMENT_VERSION - 1;

        self::assertSame('outdated', $index->verify('test/paper')['status'], 'the file did not change, but its documents would');
        self::assertSame('ok', $index->index('test/paper')['verify']['status']);
    }

    public function testVerifyNamesAFolioThatWasNeverIndexed(): void
    {
        $this->newspaper('test/paper');
        self::assertSame('missing', $this->index()->verify('test/paper')['status']);
        self::assertSame('no-folio', $this->index()->verify('test/nothing')['status']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dates')]
    public function testDatesBecomeInclusiveIntervals(mixed $value, ?string $start, ?string $end): void
    {
        self::assertSame([$start, $end], FolioSearchProjector::dates($value));
    }

    public static function dates(): iterable
    {
        yield 'day' => ['1941-12-07', '1941-12-07', '1941-12-07'];
        yield 'month' => ['1940-02', '1940-02-01', '1940-02-29'];
        yield 'year' => ['1953', '1953-01-01', '1953-12-31'];
        yield 'range' => ['1940-1945', '1940-01-01', '1945-12-31'];
        yield 'circa' => ['ca. 1890', '1890-01-01', '1890-12-31'];
        yield 'none' => ['undated', null, null];
        yield 'invalid day' => ['1941-13-45', '1941-01-01', '1941-12-31'];
    }

    private function newspaper(string $code): void
    {
        $db = new \PDO('sqlite:'.$this->folios->path($code, createDirectory: true));
        $db->exec('CREATE TABLE item (id TEXT PRIMARY KEY, local_id TEXT, label TEXT, dto_type TEXT, dto_data TEXT, extras TEXT, core_id TEXT)');
        $db->exec('CREATE TABLE page (id TEXT, row_id TEXT, seq INT, type TEXT, url TEXT)');
        $db->exec('CREATE TABLE link (left_core TEXT, left_id TEXT, right_core TEXT, right_id TEXT)');
        $db->exec('CREATE TABLE schema_property (name TEXT, label TEXT, type TEXT, visible INT, facet INT, filterable INT, searchable INT, position INT, table_id TEXT)');
        $db->exec("INSERT INTO schema_property VALUES ('state', 'State', '?string', 1, 1, 1, 0, 1, 'x')");
        $insert = $db->prepare('INSERT INTO item VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$code.':doc:i1', 'i1', 'January 1, 1953', 'newspaper', json_encode(['date' => '1953-01-01']), null, $code.':doc']);
        foreach (['i1-a1' => 'Tractor for sale', 'i1-a2' => 'Church supper'] as $localId => $title) {
            $insert->execute([$code.':article:'.$localId, $localId, $title, 'document',
                json_encode(['date' => '1953-01-01', 'state' => 'Virginia', 'rights' => 'CC 0', 'ocrText' => strtolower($title).' tractor']),
                json_encode(['issueId' => 'i1', 'articleTier' => 'block', 'recordKind' => 'advertisement', 'pdfPage' => 1]), $code.':article']);
            $db->prepare('INSERT INTO page VALUES (?, ?, 2, ?, ?)')->execute([$code.':article:'.$localId.'#2', $code.':article:'.$localId, 'scan', 's3://bucket/'.$localId.'-2.jpg']);
            $db->prepare('INSERT INTO page VALUES (?, ?, 1, ?, ?)')->execute([$code.':article:'.$localId.'#1', $code.':article:'.$localId, 'scan', 's3://bucket/'.$localId.'-1.jpg']);
            $db->prepare('INSERT INTO link VALUES (?, ?, ?, ?)')->execute(['article', $localId, 'doc', 'i1']);
        }
    }

    private function index(string $source = 'sqlite'): FolioSearchIndex
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
            $body = isset($options['body']) && str_starts_with((string) $options['body'], '{') ? json_decode($options['body'], true) : null;
            $parts = explode('/', ltrim($path, '/'));
            $index = $parts[0];
            if ('HEAD' === $method) {
                return new MockResponse('', ['http_code' => isset($this->mappings[$index]) ? 200 : 404]);
            }
            if ('PUT' === $method && 1 === count($parts)) {
                $this->mappings[$index] = $body['mappings'];
                $this->docs[$index] = [];

                return new MockResponse('{}');
            }
            if ('PUT' === $method && str_ends_with($path, '/_mapping')) {
                $this->mappings[$index]['properties'] += $body['properties'];

                return new MockResponse('{}');
            }
            if (str_ends_with($path, '/_mapping')) {
                return new MockResponse(json_encode([$index => ['mappings' => $this->mappings[$index]]]));
            }
            if ('/_bulk' === $path) {
                $lines = explode("\n", trim((string) $options['body']));
                for ($i = 0; $i < count($lines); $i += 2) {
                    $action = json_decode($lines[$i], true)['index'];
                    if ($this->dropOnBulk && 0 === $i) {
                        continue; // ES "accepted" the batch but this document never arrived
                    }
                    $this->docs[$action['_index']][$action['_id']] = json_decode($lines[$i + 1], true);
                }

                return new MockResponse('{"errors":false,"items":[]}');
            }
            if (in_array($parts[1] ?? null, ['_doc'], true)) {
                $id = implode('/', array_slice($parts, 2));
                if ('PUT' === $method) {
                    $this->docs[$index][$id] = $body;

                    return new MockResponse('{}');
                }
                if ('GET' === $method) {
                    return isset($this->docs[$index][$id])
                        ? new MockResponse(json_encode(['found' => true, '_source' => $this->docs[$index][$id]]))
                        : new MockResponse('{"found":false}', ['http_code' => 404]);
                }
            }
            if (str_ends_with($path, '/_refresh')) {
                return new MockResponse('{}');
            }
            if (str_ends_with($path, '/_count') || str_ends_with($path, '/_delete_by_query')) {
                if (!isset($this->docs[$index])) {
                    return new MockResponse('{"status":404}', ['http_code' => 404]);
                }
                $matching = array_filter($this->docs[$index], fn (array $doc): bool => $this->queryMatches($doc, $body['query']));
                if (str_ends_with($path, '/_count')) {
                    return new MockResponse(json_encode(['count' => count($matching)]));
                }
                $this->docs[$index] = array_diff_key($this->docs[$index], $matching);

                return new MockResponse(json_encode(['deleted' => count($matching)]));
            }

            return new MockResponse('{}', ['http_code' => 500]);
        });

        $projector = new FolioSearchProjector(new FolioFtsIndexer());

        return new FolioSearchIndex($this->folios, $projector, new FolioElasticClient($http, 'elasticsearch://localhost:9200'), source: $source);
    }

    /** The term/bool subset the index sends. */
    private function queryMatches(array $doc, array $query): bool
    {
        if (isset($query['term'])) {
            $field = array_key_first($query['term']);

            return ($doc[$field] ?? null) === $query['term'][$field];
        }
        foreach ($query['bool']['filter'] ?? [] as $clause) {
            if (!$this->queryMatches($doc, $clause)) {
                return false;
            }
        }
        foreach ($query['bool']['must_not'] ?? [] as $clause) {
            if ($this->queryMatches($doc, $clause)) {
                return false;
            }
        }

        return true;
    }
}
