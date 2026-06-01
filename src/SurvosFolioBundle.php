<?php

declare(strict_types=1);

namespace Survos\FolioBundle;

use Survos\IiifBundle\SurvosIiifBundle;
use Survos\ImgproxyBundle\SurvosImgproxyBundle;
use Survos\FolioBundle\Command\{FolioArchiveCommand,FolioBrowseCommand,FolioBuildCommand,FolioFtsRebuildCommand,FolioInfoCommand,FolioIngestCommand,FolioMigrateCommand,FolioPublishCommand,FolioPullCommand,FolioRestoreCommand};
use Survos\FolioBundle\EventListener\{FolioContextListener,FolioFtsIndexListener};
use Survos\FolioBundle\Menu\FolioMenu;
use Survos\FolioBundle\Controller\{FolioCollectionController,FolioController,FolioSearchController};
use Survos\FolioBundle\Repository\{CoreRepository,FolioRepository,LinkRepository,LinkTypeRepository,RowRepository,TermRepository,TermSetRepository};
use Survos\FolioBundle\Service\{FolioAiArtifactPaths,FolioAiBatchPreparer,FolioAiClaimImporter,FolioAiPromptBuilder,FolioArchivePreparer,FolioArchiveService,FolioMeiliDocumentBuilder,FolioMeiliIndexer,FolioChatPromptSuggester,FolioChatService,FolioDocsBuilder,FolioDtoTypeResolver,FolioFtsIndexer,FolioIngestService,FolioQueryAnalyzer,FolioRegistry,FolioRetriever,FolioSchemaManager,FolioSchemaSnapshotter,FolioService,FolioViewBuilder,FolioSummaryService,FolioWordCloudService};
use Survos\FolioBundle\State\FolioRowProvider;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\{ContainerBuilder,ContainerInterface,Reference};
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

#[RequiredBundle(SurvosIiifBundle::class, ignoreOnInvalid: true)]
#[RequiredBundle(SurvosImgproxyBundle::class, ignoreOnInvalid: true)]
final class SurvosFolioBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->scalarNode('extension')->defaultValue('folio')->end()
            ->scalarNode('entity_manager')->defaultValue('folio')->end()
            ->scalarNode('folio_server')
                ->info('Base URL of the app that hosts the full folio UX. Null = this app. Used to build browse links (e.g. harvest -> https://zm.example).')
                ->defaultNull()
            ->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();
        foreach ([FolioRepository::class, CoreRepository::class, RowRepository::class, TermSetRepository::class, TermRepository::class, LinkTypeRepository::class, LinkRepository::class] as $class) {
            $services->set($class)->autowire()->autoconfigure()->public()->tag('doctrine.repository_service');
        }
        $services->set(FolioSchemaManager::class)->autowire()->autoconfigure()->public();
        $services->set(FolioRegistry::class)->autowire()->autoconfigure()->public();
        $services->set(FolioRegistry::class)->autowire()->autoconfigure()->public();
        $services->set(FolioSummaryService::class)->autowire()->autoconfigure()->public();
        $services->set(FolioFtsIndexer::class)->autowire()->autoconfigure()->public();
        $services->set(FolioQueryAnalyzer::class)->autowire()->autoconfigure()->public();
        $services->set(FolioRetriever::class)->autowire()->autoconfigure()->public();
        $services->set(FolioWordCloudService::class)->autowire()->autoconfigure()->public();
        $services->set(FolioChatPromptSuggester::class)->autowire()->autoconfigure()->public();
        $services->set(FolioChatService::class)->autowire()->autoconfigure()->public()->args([
            '$agent' => new Reference('ai.agent.folio', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);
        $services->set(FolioSchemaSnapshotter::class)->autowire()->autoconfigure()->public();
        $services->set(FolioViewBuilder::class)->autowire()->autoconfigure()->public();
        $services->set(FolioDocsBuilder::class)->autowire()->autoconfigure()->public();
        $services->set(FolioArchivePreparer::class)->autowire()->autoconfigure()->public();
        $services->set(FolioArchiveService::class)->autowire()->autoconfigure()->public();
        $services->set(FolioIngestService::class)->autowire()->autoconfigure()->public();
        $services->set(FolioAiArtifactPaths::class)->autowire()->autoconfigure()->public();
        $services->set(FolioAiPromptBuilder::class)->autowire()->autoconfigure()->public();
        $services->set(FolioAiBatchPreparer::class)->autowire()->autoconfigure()->public();
        $services->set(FolioAiClaimImporter::class)->autowire()->autoconfigure()->public();
        if (class_exists(\Survos\MeiliBundle\Service\MeiliService::class)) {
            $services->set(FolioMeiliDocumentBuilder::class)->autowire()->autoconfigure()->public();
            $services->set(FolioMeiliIndexer::class)->autowire()->autoconfigure()->public();
        }
        $services->set(FolioService::class)->autowire()->autoconfigure()->public()->args([
            '$folioEntityManager' => new Reference(sprintf('doctrine.orm.%s_entity_manager', $config['entity_manager'])),
            '$extension' => $config['extension'],
        ]);
        $services->set(FolioBuildCommand::class)->autowire()->autoconfigure()->public()->args([
            '$folioServer' => $config['folio_server'],
            '$kernelDebug' => '%kernel.debug%',
        ]);
        foreach ([FolioMigrateCommand::class, FolioIngestCommand::class, FolioInfoCommand::class, FolioBrowseCommand::class, FolioFtsRebuildCommand::class, FolioArchiveCommand::class, FolioRestoreCommand::class, FolioPublishCommand::class, FolioPullCommand::class, FolioCollectionController::class, FolioController::class, FolioSearchController::class, FolioContextListener::class, FolioDtoTypeResolver::class] as $class) {
            $services->set($class)->autowire()->autoconfigure()->public();
        }
        if (interface_exists(\ApiPlatform\State\ProviderInterface::class)) {
            $services->set(FolioRowProvider::class)->autowire()->autoconfigure()->public();
        }
        if (class_exists(\Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber::class)) {
            $services->set(FolioMenu::class)->autowire()->autoconfigure()->public();
        }
        $services->set(FolioFtsIndexListener::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->tag('kernel.event_listener', ['event' => 'Survos\FolioBundle\Event\FolioIngestFinishedEvent']);
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
