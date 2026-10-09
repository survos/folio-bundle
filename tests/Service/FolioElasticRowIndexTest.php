<?php

declare(strict_types=1);
namespace Survos\FolioBundle\Tests\Service;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Path\DataPaths;
use Survos\FolioBundle\Service\{FolioService, FolioSchemaManager, FolioElasticRowIndex, FolioElasticClient, FolioFtsIndexer};
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FolioElasticRowIndexTest extends TestCase
{
    private string $root;
    private FolioService $folios;
    private array $documents = [];
    private ?array $mapping = null;
    private bool $failBulk = false;
    private int $deletes = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/folio-es-test-'.bin2hex(random_bytes(6));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getEventManager')->willReturn(new EventManager());
        $this->folios = new FolioService($em, new FolioSchemaManager(new ArrayAdapter()), new DataPaths($this->root));
    }

    protected function tearDown(): void { (new Filesystem())->remove($this->root); }

    private function source(string $scope, array $rows): void
    {
        $path = $this->folios->path($scope, createDirectory: true);
        $db = new \PDO('sqlite:'.$path);
        $db->exec('CREATE TABLE IF NOT EXISTS item (id TEXT, local_id TEXT, label TEXT, dto_type TEXT, dto_data TEXT, extras TEXT, core_id TEXT)');
        $db->exec('DELETE FROM item');
        foreach ($rows as $core => $label) {
            // The source and translation intentionally have the same INTERNAL identity.
            $db->prepare('INSERT INTO item VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([
                'test/paper:'.$core.':1', '1', $label, null, json_encode(['title'=>$label]), null, 'test/paper:'.$core,
            ]);
        }
    }

    private function index(): FolioElasticRowIndex
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = parse_url($url, PHP_URL_PATH);
            $body = isset($options['body']) ? json_decode($options['body'], true) : null;
            if ($method === 'HEAD') { return new MockResponse('', ['http_code'=>$this->mapping === null ? 404 : 200]); }
            if ($method === 'PUT') { $this->mapping = $body; return new MockResponse('{}'); }
            if (str_ends_with($path, '/_mapping')) { return new MockResponse(json_encode(['test_index'=>$this->mapping])); }
            if ($path === '/_bulk') {
                if ($this->failBulk) { return new MockResponse(json_encode(['errors'=>true,'items'=>[['index'=>['_id'=>'bad','error'=>['reason'=>'rejected']]]]])); }
                $lines = explode("\n", trim($options['body']));
                for ($i=0; $i<count($lines); $i+=2) {
                    $action = json_decode($lines[$i], true); $document = json_decode($lines[$i+1], true);
                    $this->documents[$action['index']['_id']] = $document;
                }
                return new MockResponse('{"errors":false}');
            }
            if (str_ends_with($path, '/_refresh')) { return new MockResponse('{}'); }
            if (str_ends_with($path, '/_delete_by_query')) {
                ++$this->deletes; $removed=0;
                $scope=$body['query']['bool']['filter'][0]['term']['folioCode'];
                $build=$body['query']['bool']['must_not'][0]['term']['build'];
                foreach ($this->documents as $id=>$doc) {
                    if ($doc['folioCode']===$scope && $doc['build']!==$build) { unset($this->documents[$id]);++$removed; }
                }
                return new MockResponse(json_encode(['deleted'=>$removed]));
            }
            if (str_ends_with($path, '/_search')) {
                $filter=$body['query']['bool']['filter']; $hits=[];
                foreach ($this->documents as $id=>$doc) {
                    if ($doc['folioCode']!==$filter[0]['term']['folioCode']) { continue; }
                    if (isset($filter[1]) && $doc['coreCode']!==$filter[1]['term']['coreCode']) { continue; }
                    $hits[]=['_id'=>$id,'_source'=>['rowId'=>$doc['rowId']]];
                }
                return new MockResponse(json_encode(['hits'=>['hits'=>$hits]]));
            }
            throw new \LogicException('Unexpected request '.$method.' '.$path);
        });
        return new FolioElasticRowIndex($this->folios, new FolioFtsIndexer(), new FolioElasticClient($http, 'elasticsearch://localhost:9200'), index:'test_index');
    }

    public function testCoresAndTranslationsCoexistAndCleanupIsScoped(): void
    {
        $this->source('test/paper', ['obj'=>'Original object','doc'=>'Original document','person'=>'Original person','org'=>'Original organization']);
        $this->source('test/paper.en', ['obj'=>'Translated object']);
        $index=$this->index();
        self::assertSame(4, $index->index('test/paper')['rows']);
        self::assertSame(1, $index->index('test/paper.en')['rows']);
        self::assertCount(5, $this->documents);
        self::assertSame(['test/paper:obj:1'], $index->match('test/paper.en', 'object', 'obj'));
        self::assertSame(['test/paper:doc:1'], $index->match('test/paper', 'document', 'doc'));
        $this->source('test/paper', ['obj'=>'Updated source']);
        self::assertSame(3, $index->index('test/paper')['removed']);
        self::assertCount(2, $this->documents);
        self::assertSame('standard', $this->mapping['mappings']['properties']['body']['analyzer']);
    }

    public function testFailedBulkDoesNotRemovePreviouslyIndexedRows(): void
    {
        $this->source('test/paper', ['obj'=>'Existing']); $index=$this->index();$index->index('test/paper');
        $before=$this->documents;$deletes=$this->deletes;$this->failBulk=true;
        try { $index->index('test/paper');self::fail('Accepted failed bulk'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('rejected', $e->getMessage()); }
        self::assertSame($before,$this->documents);self::assertSame($deletes,$this->deletes);
    }

    public function testConcurrentRunCannotReachStaleCleanup(): void
    {
        $lock = fopen(sys_get_temp_dir().'/folio-es-'.hash('sha256', 'test_index:test/paper').'.lock', 'c');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            try { $this->index()->index('test/paper');self::fail('Concurrent writer accepted'); }
            catch (\RuntimeException $e) { self::assertStringContainsString('Another index run', $e->getMessage()); }
            self::assertSame(0, $this->deletes);
        } finally { flock($lock, LOCK_UN);fclose($lock); }
    }

    public function testProducerQueuesPublishedTranslationAndReaderDoesNot(): void
    {
        $event = new \Survos\FolioBundle\Event\FolioPublishedEvent('test/paper', '/published/paper.en.folio', 1, 'en');
        self::assertSame('test/paper.en', $event->folioCode);
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(function ($message) {
            self::assertSame('test/paper.en', $message->folioCode);
            return new \Symfony\Component\Messenger\Envelope($message);
        });
        (new \Survos\FolioBundle\EventListener\FolioElasticIndexListener($this->index(), $bus))($event);
        (new \Survos\FolioBundle\EventListener\FolioElasticIndexListener($this->index(), $bus, enabled: true))($event);
    }

    public function testLegacyMappingRequiresAnExplicitMigration(): void
    {
        $this->mapping=['mappings'=>['properties'=>['body'=>['type'=>'text','analyzer'=>'english']]]];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Legacy Folio ES mapping');
        $this->index()->index('test/paper');
    }
}
