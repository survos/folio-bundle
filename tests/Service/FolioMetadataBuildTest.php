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

    public function testBuildAnnouncesTheFinalReadableArtifact(): void
    {
        $code = 'test/published';
        $dataset = new DatasetInfo($code);
        $dataset->label = 'Published fixture';
        $dataset->cores = ['doc'];
        $dir = $this->paths->stageDir($code, 'normalized');
        (new Filesystem())->mkdir($dir);
        file_put_contents($dir.'/doc.jsonl', json_encode([
            \Survos\DataContracts\Vocabulary\ItemField::ID => '1',
            \Survos\DataContracts\Vocabulary\ItemField::TITLE => 'Published issue',
            \Survos\DataContracts\Vocabulary\ItemField::CONTENT_TYPE => \Survos\DataContracts\Metadata\ContentType::NEWSPAPER,
        ], JSON_THROW_ON_ERROR)."\n");

        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repo->expects(self::once())->method('find')->with($code)->willReturn($dataset);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(DatasetInfo::class)->willReturn($repo);
        $registry = new FolioRegistry($this->paths, $em);
        $state = new JsonlStateService();
        $resolver = new FolioDtoTypeResolver();
        $ingest = new FolioIngestService($this->folios, $registry, $resolver, new FolioSummaryService(), $this->paths, new JsonlCountService($state));
        $views = new \Survos\FolioBundle\Service\FolioViewBuilder();
        $preparer = new \Survos\FolioBundle\Service\FolioArchivePreparer(
            new \Survos\FolioBundle\Service\FolioSchemaSnapshotter($resolver),
            $views,
            new \Survos\FolioBundle\Service\FolioDocsBuilder(),
        );
        $archive = new \Survos\FolioBundle\Service\FolioArchiveService($this->folios, new \Survos\FolioBundle\Service\FolioFtsIndexer(), $preparer, $views);
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $events = [];
        $finalPath = $this->folios->path($code);
        $dispatcher->addListener(\Survos\DatasetBundle\Event\DatasetArtifactUpdatedEvent::class, function ($event) use (&$events, $finalPath): void {
            self::assertSame(\Survos\DatasetBundle\Entity\Artifact::TYPE_FOLIO, $event->type);
            self::assertSame($finalPath, $event->uri);
            self::assertFileIsReadable($event->uri);
            self::assertFileDoesNotExist($finalPath.'.building');
            $pdo = new \PDO('sqlite:file:'.$event->uri.'?mode=ro');
            self::assertSame(1, (int) $pdo->query('SELECT count(*) FROM item')->fetchColumn());
            self::assertSame(1, $event->rowCount);
            $events[] = $event;
        });
        $command = new \Survos\FolioBundle\Command\FolioBuildCommand(
            $ingest, $registry, $this->folios, $archive, $preparer,
            $this->createStub(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class),
            $state, '/folio', $dispatcher,
        );
        $io = new \Symfony\Component\Console\Style\SymfonyStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput(),
        );
        self::assertSame(0, $command($io, dataset: $code));
        self::assertCount(1, $events);
        $state->closeAll();
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

    public function testPageTypesRoundTripAndInvalidBuildPreservesLiveFolio(): void
    {
        $code = 'test/pages';
        $dataset = new DatasetInfo($code);
        $dataset->label = 'Page types';
        $dataset->cores = ['doc'];
        $dir = $this->paths->stageDir($code, 'normalized');
        (new Filesystem())->mkdir($dir);
        file_put_contents($dir.'/doc.jsonl', json_encode(['id' => '1', 'label' => 'Document', 'contentType' => 'document'], JSON_THROW_ON_ERROR)."\n");
        $pages = [];
        foreach (\Survos\Folio\Enum\PageType::cases() as $index => $type) {
            $pages[] = json_encode(new \Survos\FolioBundle\Dto\PageDto(
                coreCode: 'doc', localId: '1', url: 'https://example.org/page/'.$index,
                seq: $index + 1, type: $type,
            ), JSON_THROW_ON_ERROR);
        }
        file_put_contents($dir.'/page.jsonl', implode("\n", $pages)."\n");
        $ingest = new FolioIngestService($this->folios, $this->registry, new FolioDtoTypeResolver(), new FolioSummaryService(), $this->paths, new JsonlCountService(new JsonlStateService()));
        $this->folios->buildAt($code);
        $ingest->ingestDataset($dataset, dispatchFinished: false);
        $this->folios->finalize();
        $this->folios->finishBuildAt($code);
        $ctx = $this->folios->context($code);
        $entities = $ctx->em->getRepository(\Survos\FolioBundle\Entity\Page::class)->findBy([], ['seq' => 'ASC']);
        self::assertSame(\Survos\Folio\Enum\PageType::cases(), array_map(static fn ($page) => $page->type, $entities));
        self::assertSame(\Survos\Folio\Enum\PageType::Photo, \Survos\FolioBundle\Enum\PageType::Photo);
        $this->folios->finalize();
        $before = hash_file('sha256', $this->folios->path($code));
        $invalid = json_decode($pages[0], true, flags: JSON_THROW_ON_ERROR);
        $invalid[\Survos\DataContracts\Vocabulary\ItemField::TYPE] = 'object';
        file_put_contents($dir.'/page.jsonl', json_encode($invalid, JSON_THROW_ON_ERROR)."\n");
        $this->folios->buildAt($code);
        try {
            $ingest->ingestDataset($dataset, dispatchFinished: false);
            self::fail('An object genre must never be accepted as a page type');
        } catch (\ValueError $e) {
            self::assertStringContainsString('object', $e->getMessage());
        } finally {
            $this->folios->discardBuildAt($code);
        }
        self::assertSame($before, hash_file('sha256', $this->folios->path($code)));
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
