<?php

declare(strict_types=1);

namespace Survos\FolioBundle;

use Survos\IiifBundle\SurvosIiifBundle;
use Survos\ImgproxyBundle\SurvosImgproxyBundle;
use Survos\FolioBundle\Command\{FolioArchiveCommand,FolioBrowseCommand,FolioBuildCommand,FolioFtsRebuildCommand,FolioInfoCommand,FolioIngestCommand,FolioMigrateCommand,FolioPublishCommand,FolioPullCommand,FolioRestoreCommand};
use Survos\FolioBundle\EventListener\{BuildFolioRequestedListener,FolioContextListener,FolioFtsIndexListener};
use Survos\FolioBundle\Menu\FolioMenu;
use Survos\FolioBundle\Controller\{FolioCollectionController,FolioController,FolioSearchController};
use Survos\FolioBundle\Repository\{CoreRepository,FolioRepository,LinkRepository,LinkTypeRepository,RowRepository,TermRepository,TermSetRepository};
use Survos\FolioBundle\Service\{FolioAiArtifactPaths,FolioAiBatchPreparer,FolioAiClaimImporter,FolioAiPromptBuilder,FolioArchivePreparer,FolioArchiveService,FolioMeiliDocumentBuilder,FolioMeiliIndexer,FolioChatContextHolder,FolioChatPromptSuggester,FolioChatService,FolioChatTools,FolioDocsBuilder,FolioDtoTypeResolver,FolioFtsIndexer,FolioIngestService,FolioQueryAnalyzer,FolioRegistry,FolioRetriever,FolioSchemaManager,FolioSchemaSnapshotter,FolioService,FolioViewBuilder,FolioSummaryService,FolioWordCloudService};
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Survos\FolioBundle\State\FolioRowProvider;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\{ContainerBuilder,ContainerInterface,Reference};
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

#[RequiredBundle(SurvosIiifBundle::class, ignoreOnInvalid: true)]
#[RequiredBundle(SurvosImgproxyBundle::class, ignoreOnInvalid: true)]
final class SurvosFolioBundle extends AbstractBundle
{
    use HasConfigurableRoutes;

    public function configure(DefinitionConfigurator $definition): void
    {
        $children = $definition->rootNode()->children();
        $this->addRouteOptions($children, '');
        $children
            ->booleanNode('admin_navbar_menu')->defaultTrue()
                ->info('Set false to disable this bundle\'s admin navbar menu entries.')
            ->end()
            ->booleanNode('build_archive')->defaultFalse()
                ->info('Also build the compressed .folio.gz archive + register the FOLIO_ARCHIVE artifact on inline workflow builds (set true on publishing/prod hosts; off locally — the .gz is slow and unused for browsing).')
            ->end()
            ->scalarNode('extension')->defaultValue('folio')->end()
            ->scalarNode('entity_manager')->defaultValue('folio')->end()
            ->scalarNode('folio_server')
                ->info('Base URL of the live folio site — hosts the full folio UX and the folio archive API. Used for browse links and as the default folio:pull source (GET <server>/folio/list.json).')
                ->defaultValue('https://zm.survos.com')
            ->end()
        ->end();
    }

    public function build(ContainerBuilder $container): void
    {
        $this->addRouteLoaderCompilerPass($container);
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->captureRouteConfig($config);
        $builder->setParameter('survos_folio.folio_server', $config['folio_server']);
        $services = $container->services();
        foreach ([FolioRepository::class, CoreRepository::class, RowRepository::class, TermSetRepository::class, TermRepository::class, LinkTypeRepository::class, LinkRepository::class] as $class) {
            $services->set($class)->autowire()->autoconfigure()->public()->tag('doctrine.repository_service');
        }
        $services->set(FolioSchemaManager::class)->autowire()->autoconfigure()->public();
        $services->set(FolioRegistry::class)->autowire()->autoconfigure()->public()
            // Auto-wire the dataset registry EM only if dataset-bundle is loaded; null otherwise,
            // so a bare app can require folio-bundle, pull a folio, and display it — no dataset infra.
            ->arg('$datasetEntityManager', service('doctrine.orm.dataset_entity_manager')->ignoreOnInvalid());
        $services->set(FolioSummaryService::class)->autowire()->autoconfigure()->public();
        $services->set(FolioFtsIndexer::class)->autowire()->autoconfigure()->public();
        $services->set(FolioQueryAnalyzer::class)->autowire()->autoconfigure()->public();
        $services->set(FolioRetriever::class)->autowire()->autoconfigure()->public();
        $services->set(FolioChatContextHolder::class)->autowire()->autoconfigure()->public();
        // autoconfigure() applies the ai-bundle's #[AsTool] autoconfiguration, tagging this ai.tool.
        $services->set(FolioChatTools::class)->autowire()->autoconfigure()->public();
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
        foreach ([FolioMigrateCommand::class, FolioIngestCommand::class, FolioInfoCommand::class, FolioBrowseCommand::class, FolioFtsRebuildCommand::class, FolioArchiveCommand::class, FolioRestoreCommand::class, FolioPublishCommand::class, FolioPullCommand::class, FolioContextListener::class, FolioDtoTypeResolver::class] as $class) {
            $services->set($class)->autowire()->autoconfigure()->public();
        }
        $services->set(BuildFolioRequestedListener::class)->autowire()->autoconfigure()->public()
            ->arg('$buildArchive', $config['build_archive']);
        $services->set(\Survos\FolioBundle\Twig\FolioCoreTwig::class)->autowire()->autoconfigure()->public();
        if ($config['routes_enabled']) {
            foreach ([FolioCollectionController::class, FolioController::class, FolioSearchController::class] as $class) {
                $services->set($class)->autowire()->autoconfigure()->public();
            }
        }
        if (interface_exists(\ApiPlatform\State\ProviderInterface::class)) {
            $services->set(FolioRowProvider::class)->autowire()->autoconfigure()->public();
        }
        if ($config['admin_navbar_menu'] && class_exists(\Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber::class)) {
            $services->set(FolioMenu::class)->autowire()->autoconfigure()->public()->args([
                '$folioServer' => $config['folio_server'],
            ]);
        }
        $this->registerRouteLoader($builder);
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

}
