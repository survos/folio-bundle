<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Command;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Path\DataPaths;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\FolioBundle\Catalog\FolioCatalogClient;
use Survos\FolioBundle\Command\FolioMigrateCommand;
use Survos\FolioBundle\Command\FolioPullCommand;
use Survos\FolioBundle\Command\FolioValidateCommand;
use Survos\FolioBundle\Service\FolioRegistry;
use Survos\FolioBundle\Service\FolioService;
use Survos\FolioBundle\Set\FolioSetResolver;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Harvest alone owns the dataset registry. An app on Harvest's dataset API (dataset_api enabled) is
 * a consumer: it may share Harvest's folio files, never its database — even when a registry EM is
 * wired (zm shares APP_DATA_DIR, and so datasets.db, with the builder).
 *
 * folio_server is deliberately NOT the consumer signal: Harvest sets it too, for browse links to zm.
 */
final class RegistryFreeConsumerTest extends TestCase
{
    private const SERVER = 'https://harvest.example';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/folio-consumer-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testFolioPullHasNoRegistryDependency(): void
    {
        foreach ((new \ReflectionMethod(FolioPullCommand::class, '__construct'))->getParameters() as $parameter) {
            self::assertStringNotContainsString('EntityManager', (string) $parameter->getType(), 'folio:pull must not take a registry entity manager: $'.$parameter->getName());
        }
        self::assertFalse(method_exists(FolioPullCommand::class, 'registerRestoredFolio'), 'pull bookkeeping belongs to Harvest');
    }

    public function testSetsResolveAgainstTheDatasetApiEvenWithoutAFolioServer(): void
    {
        self::assertSame('catalog', $this->resolver($this->apiCatalog(), folioServer: '')->source());
        self::assertSame('catalog', $this->resolver($this->plainCatalog('https://zm.example'), folioServer: 'https://zm.example')->source());
        // The builder: no dataset API, no folio_server.
        self::assertSame('registry', $this->resolver($this->plainCatalog(''), folioServer: '')->source());
    }

    public function testValidateReadsKnownDatasetsFromTheApiNotTheRegistry(): void
    {
        $command = new FolioValidateCommand($this->folioService(), new EventDispatcher(), $this->untouchableEm(), $this->apiCatalog(['mus/a', 'mus/b']));

        self::assertSame(['mus/a' => true, 'mus/b' => true], $this->knownDatasetKeys($command));
    }

    public function testValidateWithAnUnreachableEmptyCatalogSkipsOrphanDetection(): void
    {
        // No cache and the hub is down: "don't know", not "every folio on disk is an orphan".
        $http = new MockHttpClient(static fn () => throw new \RuntimeException('connection refused'));
        $catalog = new FolioCatalogClient($http, '', $this->dir.'/missing.json', datasets: new DatasetPublicationClient($http, self::SERVER, 'token'));
        $command = new FolioValidateCommand($this->folioService(), new EventDispatcher(), $this->untouchableEm(), $catalog);

        self::assertNull($this->knownDatasetKeys($command));
    }

    public function testRegistryRefusesOnADatasetApiConsumer(): void
    {
        $registry = new FolioRegistry(new DataPaths($this->dir), $this->untouchableEm(), $this->apiCatalog());
        self::assertFalse($registry->hasDatasetRegistry());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('run this on Harvest');
        $registry->datasets(all: true);
    }

    public function testRegistryStillAnswersOnTheBuilder(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        self::assertTrue((new FolioRegistry(new DataPaths($this->dir), $em, $this->plainCatalog('https://zm.example')))->hasDatasetRegistry());
        self::assertFalse((new FolioRegistry(new DataPaths($this->dir)))->hasDatasetRegistry());
    }

    public function testMigrateWithoutARegistrySaysRunOnHarvest(): void
    {
        $tester = new CommandTester(new FolioMigrateCommand($this->folioService(), new FolioRegistry(new DataPaths($this->dir), $this->untouchableEm(), $this->apiCatalog())));

        self::assertSame(1, $tester->execute(['--all' => true]));
        self::assertStringContainsString('Run it on Harvest', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    /** A registry EM a consumer must never use: any call fails the test. */
    private function untouchableEm(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method(self::anything());

        return $em;
    }

    /** A dataset-API catalog served from a fresh cache, so no request is made. @param list<string> $keys */
    private function apiCatalog(array $keys = ['mus/a']): FolioCatalogClient
    {
        $cache = $this->dir.'/api-catalog.json';
        file_put_contents($cache, json_encode([
            'source' => self::SERVER.'/api/datasets',
            'fetchedAt' => time(),
            'folios' => array_map(static fn (string $key): array => ['datasetKey' => $key], $keys),
        ], JSON_THROW_ON_ERROR));
        $http = new MockHttpClient(static fn () => throw new \LogicException('No request expected'));

        return new FolioCatalogClient($http, '', $cache, datasets: new DatasetPublicationClient($http, self::SERVER, 'token'));
    }

    private function plainCatalog(string $server): FolioCatalogClient
    {
        return new FolioCatalogClient(new MockHttpClient(), $server, $this->dir.'/plain-catalog.json');
    }

    private function resolver(FolioCatalogClient $catalog, string $folioServer): FolioSetResolver
    {
        return new FolioSetResolver([], $this->dir, catalog: $catalog, folioServer: $folioServer);
    }

    private function folioService(): FolioService
    {
        return (new \ReflectionClass(FolioService::class))->newInstanceWithoutConstructor();
    }

    /** @return array<string, true>|null */
    private function knownDatasetKeys(FolioValidateCommand $command): ?array
    {
        return (new \ReflectionMethod($command, 'knownDatasetKeys'))->invoke($command);
    }
}
