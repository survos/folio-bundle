<?php

declare(strict_types=1);

namespace Survos\FolioBundle;

use Survos\FolioBundle\Command\{FolioBrowseCommand,FolioInfoCommand,FolioIngestCommand,FolioMigrateCommand};
use Survos\FolioBundle\EventListener\FolioContextListener;
use Survos\FolioBundle\Menu\FolioMenu;
use Survos\FolioBundle\Controller\FolioController;
use Survos\FolioBundle\Repository\{CoreRepository,FolioRepository,RelationRepository,RelationTypeRepository,RowRepository,TermRepository,TermSetRepository};
use Survos\FolioBundle\Service\{FolioDtoTypeResolver,FolioRegistry,FolioSchemaManager,FolioService,FolioSummaryService};
use Survos\FolioBundle\State\FolioRowProvider;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\{ContainerBuilder,Reference};
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class SurvosFolioBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('db_dir')->defaultValue('%env(APP_DATA_DIR)%/folio')->end()
            ->scalarNode('extension')->defaultValue('folio.sqlite')->end()
            ->scalarNode('entity_manager')->defaultValue('folio')->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        foreach ([FolioRepository::class, CoreRepository::class, RowRepository::class, TermSetRepository::class, TermRepository::class, RelationTypeRepository::class, RelationRepository::class] as $class) {
            $services->set($class)->autowire()->autoconfigure()->public()->tag('doctrine.repository_service');
        }
        $services->set(FolioSchemaManager::class)->autowire()->autoconfigure()->public();
        $services->set(FolioRegistry::class)->autowire()->autoconfigure()->public();
        $services->set(FolioRegistry::class)->autowire()->autoconfigure()->public();
        $services->set(FolioSummaryService::class)->autowire()->autoconfigure()->public();
        $services->set(FolioService::class)->autowire()->autoconfigure()->public()->args([
            '$folioEntityManager' => new Reference(sprintf('doctrine.orm.%s_entity_manager', $config['entity_manager'])),
            '$dbDir' => $config['db_dir'],
            '$extension' => $config['extension'],
        ]);
        foreach ([FolioMigrateCommand::class, FolioIngestCommand::class, FolioInfoCommand::class, FolioBrowseCommand::class, FolioController::class, FolioMenu::class, FolioContextListener::class, FolioRowProvider::class, FolioDtoTypeResolver::class] as $class) {
            $services->set($class)->autowire()->autoconfigure()->public();
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $entityDir = dirname(__DIR__) . '/src/Entity';
        if ($builder->hasExtension('api_platform')) {
            $builder->prependExtensionConfig('api_platform', ['mapping' => ['paths' => [$entityDir]]]);
        }
        if ($builder->hasExtension('twig')) {
            $builder->prependExtensionConfig('twig', ['paths' => [dirname(__DIR__) . '/templates' => 'SurvosFolioBundle']]);
        }
    }

    public function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(dirname(__DIR__) . '/src/Controller/', 'attribute');
    }
}
