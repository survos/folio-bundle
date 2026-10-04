<?php

declare(strict_types=1);
namespace Survos\FolioBundle\Tests\Service;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Metadata\DatasetDocument;
use Survos\DataContracts\Path\DataPaths;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\FolioBundle\DBAL\FolioConnectionWrapper;
use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Service\{FolioService,FolioSchemaManager,FolioRegistry,FolioIngestService,FolioDtoTypeResolver,FolioSummaryService};
use Survos\Folio\PropertyStore;
use Survos\JsonlBundle\Service\{JsonlCountService,JsonlStateService};
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Console\Tester\CommandTester;
use Survos\FolioBundle\Command\FolioMigrateCommand;

final class FolioMetadataBuildTest extends TestCase
{
    private string $root;
    private FolioService $folios;
    private FolioRegistry $registry;
    private DataPaths $paths;

    protected function setUp(): void
    {
        $this->root = (getenv('FOLIO_TEST_ROOT') ?: sys_get_temp_dir()).'/folio-properties-'.bin2hex(random_bytes(5));
        $this->paths = new DataPaths($this->root);
        $config = ORMSetup::createAttributeMetadataConfiguration([__DIR__.'/../../src/Entity'], true);
        $config->enableNativeLazyObjects(true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->root.'/unused.sqlite', 'wrapperClass' => FolioConnectionWrapper::class]), $config);
        $this->folios = new FolioService($em, new FolioSchemaManager(new ArrayAdapter()), $this->paths);
        $this->registry = new FolioRegistry($this->paths);
    }

    protected function tearDown(): void
    {
        $this->folios->closeActive();
        (new Filesystem())->remove($this->root);
    }

    public function testBuildRebuildAndFailurePreserveHumanMetadata(): void
    {
        $code = 'test/paper';
        $dataset = new DatasetInfo($code);
        $dataset->label = 'Test paper';
        $dataset->cores = ['doc'];
        $declaration = ['datasetKey' => $code, 'aggregator' => 'test', 'label' => 'Test paper', 'description' => 'An account of the town',
            'tags' => ['news'], 'extras' => ['contentType' => 'newspaper', 'titleRecord' => ['essay' => 'An essay', 'source' => 'https://example.org/title', 'subjectHeadings' => ['History']]]];
        $dataset->meta = DatasetDocument::update([], $declaration, 'test.title')['dataset'];
        $dir = $this->paths->stageDir($code, 'normalized');
        (new Filesystem())->mkdir($dir);
        $stream = fopen($dir.'/doc.jsonl', 'w');
        $sample = getenv('FOLIO_TEST_JSONL') ? fopen(getenv('FOLIO_TEST_JSONL'), 'r') : null;
        for ($i = 0; $i < 200; ++$i) {
            $line = $sample ? fgets($sample) : json_encode(['id' => (string) $i, 'label' => 'Issue '.$i, 'contentType' => 'newspaper'], JSON_THROW_ON_ERROR)."\n";
            if ($line === false) { break; }
            fwrite($stream, $line);
        }
        $expectedRows = $i;
        self::assertGreaterThan(0, $expectedRows);
        if ($sample) { fclose($sample); }
        fclose($stream);
        $ingest = new FolioIngestService($this->folios, $this->registry, new FolioDtoTypeResolver(), new FolioSummaryService(), $this->paths, new JsonlCountService(new JsonlStateService()));
        $build = function () use ($ingest, $dataset, $code, $expectedRows): void {
            $this->folios->buildAt($code);
            $result = $ingest->ingestDataset($dataset, dispatchFinished: false);
            self::assertSame($expectedRows, $result['rows']);
            $this->folios->finalize();
            $this->folios->finishBuildAt($code);
        };
        $build();
        $ctx = $this->folios->context($code);
        $folio = $ctx->em->find(Folio::class, $code);
        self::assertSame('An account of the town', $folio->description);
        self::assertSame('An essay', $folio->get('title.essay'));
        self::assertSame('https://example.org/title', $folio->properties()['title.essay']->provenance['sourceRef']);
        $folio->set('description', 'Human correction', 'human', 'editor');
        $folio->set('custom.note', ['preserve' => true], 'import', 'external');
        $ctx->em->flush(); $this->folios->finalize();
        $build();
        $ctx = $this->folios->context($code); $folio = $ctx->em->find(Folio::class, $code);
        self::assertSame('Human correction', $folio->description);
        self::assertSame('external', $folio->properties()['custom.note']->owner);
        self::assertSame($expectedRows, $folio->rowCount);
        $this->folios->finalize();
        $before = hash_file('sha256', $this->folios->path($code));
        $this->folios->buildAt($code); $this->folios->reset($code); $this->folios->discardBuildAt($code);
        self::assertSame($before, hash_file('sha256', $this->folios->path($code)), 'failed staged build leaves the live folio intact');
    }

    public function testInterruptedDirectResetCanRecoverOnRetry(): void
    {
        $code = 'test/recovery';
        $this->folios->reset($code);
        $ctx = $this->folios->context($code);
        $folio = $ctx->em->find(Folio::class, $code);
        $folio->set('description', 'Keep me', 'human', 'editor');
        $ctx->em->flush(); $this->folios->finalize();
        $this->folios->reset($code); // Simulate stopping before context restored the snapshot.
        $this->folios->reset($code);
        $ctx = $this->folios->context($code);
        self::assertSame('Keep me', $ctx->em->find(Folio::class, $code)->description);
    }

    public function testMigrateCommandConvertsOnlyRequestedFile(): void
    {
        (new Filesystem())->mkdir($this->root);
        $file = $this->root.'/legacy.folio';
        $pdo = new \PDO('sqlite:'.$file);
        $pdo->exec("CREATE TABLE folio(code TEXT, label TEXT, row_count INTEGER); INSERT INTO folio VALUES('test/paper','Legacy title',200); CREATE TABLE item(id TEXT); INSERT INTO item VALUES('untouched')");
        $command = new CommandTester(new FolioMigrateCommand($this->folios, $this->registry));
        self::assertSame(0, $command->execute(['--file' => $file]));
        self::assertSame('Legacy title', (new PropertyStore($pdo))->values()['label']);
        self::assertSame('untouched', $pdo->query('SELECT id FROM item')->fetchColumn());
        self::assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
    }
}
