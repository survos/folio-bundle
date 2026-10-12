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

/** Builder and reader services must not wire a dataset registry or removed commands. */
final class FolioServicesTest extends TestCase
{
    public function testReaderAndBuilderServicesHaveNoDatasetDependencies(): void
    {
        $builder = $this->build();
        self::assertTrue($builder->hasDefinition(DataPaths::class));
        foreach ([FolioService::class, FolioController::class, FolioSearchController::class,
            \Survos\FolioBundle\Service\FolioBuilder::class, FolioPullCommand::class, FolioValidateCommand::class] as $class) {
            self::assertTrue($builder->hasDefinition($class), $class);
        }
        foreach ([FolioCollectionController::class, BuildFolioRequestedListener::class,
            FolioBuildCommand::class, FolioArchiveCommand::class, FolioTranslateCommand::class] as $class) {
            self::assertFalse($builder->hasDefinition($class), $class);
        }
        self::assertSame([], $this->hardDatasetReferences($builder));
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
                $bad[$id] = $target;
            }
        }

        return $bad;
    }

    private function build(): ContainerBuilder
    {
        $bundle = new SurvosFolioBundle();

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
