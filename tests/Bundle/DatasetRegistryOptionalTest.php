<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Tests\Bundle;

use PHPUnit\Framework\TestCase;
use Survos\DataContracts\Path\DataPaths;
use Survos\FolioBundle\Controller\FolioCollectionController;
use Survos\FolioBundle\Controller\FolioController;
use Survos\FolioBundle\Controller\FolioSearchController;
use Survos\FolioBundle\Command\FolioArchiveCommand;
use Survos\FolioBundle\Command\FolioBuildCommand;
use Survos\FolioBundle\Command\FolioPullCommand;
use Survos\FolioBundle\Command\FolioTranslateCommand;
use Survos\FolioBundle\Command\FolioValidateCommand;
use Survos\FolioBundle\EventListener\BuildFolioRequestedListener;
use Survos\FolioBundle\Service\FolioService;
use Survos\FolioBundle\SurvosFolioBundle;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Config\FileLocator;

/**
 * survos/dataset-bundle is a SUGGEST of this bundle, not a require: it carries the production
 * registry (a second Doctrine connection, DatasetInfo/Artifact entities, API Platform resources)
 * that an app which only displays folios has no business installing. mastheads and fotostory were
 * each carrying that registry because folio-bundle's composer.json demanded it.
 *
 * These tests pin the contract in both directions, because the failure mode is silent in one and
 * fatal in the other: registering a service whose constructor (or controller action) type-hints a
 * missing class takes the whole container down at compile time, and no folio page renders.
 */
final class DatasetRegistryOptionalTest extends TestCase
{
    public function testBareReaderAppCompilesWithoutTheDatasetRegistry(): void
    {
        $builder = $this->build(hasRegistry: false);

        // The reader's own path resolution has to come from somewhere: dataset-bundle normally
        // registers DataPaths, so without it this bundle must.
        self::assertTrue($builder->hasDefinition(DataPaths::class), 'A bare app has no DataPaths service');

        // Reading folios keeps working.
        foreach ([FolioService::class, FolioController::class, FolioSearchController::class] as $class) {
            self::assertTrue($builder->hasDefinition($class), $class . ' should be available without the registry');
        }

        // The registry-bound services must NOT be registered: each would fail at compile time,
        // not at call time. FolioCollectionController's action type-hints ArtifactRepository
        // (reflected by the controller-argument pass) and BuildFolioRequestedListener's bare
        // #[AsEventListener] makes Symfony reflect __invoke(BuildFolioRequestedEvent).
        foreach ([FolioCollectionController::class, BuildFolioRequestedListener::class] as $class) {
            self::assertFalse($builder->hasDefinition($class), $class . ' needs dataset-bundle and must not be registered without it');
        }

        // Producer commands are auto-scanned from src/Command/ by the kit base, so leaving them
        // out of a registration list does nothing — they have to be actively removed. Asserting
        // by class (not by command name) is what catches the real bug here: an unimported
        // FolioValidateCommand::class silently resolves to the bundle's own namespace, so the
        // removeDefinition() call is a no-op and the command survives into a bare app.
        foreach ([FolioBuildCommand::class, FolioArchiveCommand::class, FolioTranslateCommand::class, FolioValidateCommand::class] as $class) {
            self::assertFalse($builder->hasDefinition($class), $class . ' writes the registry and must not be registered without it');
        }

        // ...while the commands a reader lives on stay.
        self::assertTrue($builder->hasDefinition(FolioPullCommand::class), 'folio:pull is how a bare app gets folios at all');

        // Nothing left may hard-require a registry class. Optional references (ignoreOnInvalid)
        // are fine and are how the reader degrades: they arrive null.
        foreach ($this->hardDatasetReferences($builder) as $serviceId => $referenced) {
            self::fail(sprintf('Service "%s" hard-requires %s without dataset-bundle installed', $serviceId, $referenced));
        }
    }

    public function testProducerServicesComeBackWhenTheRegistryIsInstalled(): void
    {
        $builder = $this->build(hasRegistry: true);

        foreach ([FolioCollectionController::class, BuildFolioRequestedListener::class, FolioBuildCommand::class, FolioValidateCommand::class] as $class) {
            self::assertTrue($builder->hasDefinition($class), $class . ' should be registered when dataset-bundle is present');
        }

        // dataset-bundle owns DataPaths then, configured from its own (richer) path config —
        // defining it here too would mean two definitions racing for the same id.
        self::assertFalse(
            $builder->hasDefinition(DataPaths::class),
            'dataset-bundle registers DataPaths; folio-bundle must not define it as well',
        );
    }

    /**
     * Service ids whose arguments reference a dataset-bundle service without ignoreOnInvalid.
     *
     * @return array<string, string>
     */
    private function hardDatasetReferences(ContainerBuilder $builder): array
    {
        $bad = [];
        foreach ($builder->getDefinitions() as $id => $definition) {
            if (!$definition instanceof Definition) {
                continue;
            }
            foreach ($definition->getArguments() as $argument) {
                if (!$argument instanceof \Symfony\Component\DependencyInjection\Reference) {
                    continue;
                }
                $target = (string) $argument;
                if (!str_starts_with($target, 'Survos\\DatasetBundle\\')) {
                    continue;
                }
                if ($argument->getInvalidBehavior() === \Symfony\Component\DependencyInjection\ContainerInterface::IGNORE_ON_INVALID_REFERENCE) {
                    continue;
                }
                $bad[$id] = $target;
            }
        }

        return $bad;
    }

    private function build(bool $hasRegistry): ContainerBuilder
    {
        // SurvosFolioBundle is final, and this process necessarily has dataset-bundle on its
        // autoloader, so the only way to exercise the bare-app branch is to preset the cached
        // flag the bundle consults.
        $bundle = new SurvosFolioBundle();
        $flag = new \ReflectionProperty(SurvosFolioBundle::class, 'datasetRegistryAvailable');
        $flag->setValue($bundle, $hasRegistry);

        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.project_dir', sys_get_temp_dir());
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.debug', false);

        $instanceof = [];
        $configurator = new ContainerConfigurator(
            $builder,
            new PhpFileLoader($builder, new FileLocator()),
            $instanceof,
            __DIR__,
            __FILE__,
        );

        $bundle->loadExtension($this->processedConfig($bundle), $configurator, $builder);

        return $builder;
    }

    /** Defaults straight from the bundle's own configuration tree, so this cannot drift from it. */
    private function processedConfig(SurvosFolioBundle $bundle): array
    {
        $extension = $bundle->getContainerExtension();
        $configuration = $extension?->getConfiguration([], new ContainerBuilder());
        self::assertNotNull($configuration);

        return (new Processor())->processConfiguration($configuration, [[]]);
    }
}
