<?php

declare(strict_types=1);

namespace Survos\FolioBundle;

use Survos\IiifBundle\SurvosIiifBundle;
use Survos\ImgproxyBundle\SurvosImgproxyBundle;
use Survos\FolioBundle\Bookmark\Service\BookmarkManager;
use Survos\FolioBundle\Command\{FolioArchiveCommand,FolioBrowseCommand,FolioBuildCommand,FolioFtsRebuildCommand,FolioInfoCommand,FolioIngestCommand,FolioMigrateCommand,FolioPublishCommand,FolioPullCommand,FolioRestoreCommand,FolioTranslateCommand};
use Survos\FolioBundle\EventListener\{BuildFolioRequestedListener,FolioFtsIndexListener,FolioRouteAttributeListener};
use Survos\FolioBundle\Menu\FolioMenu;
use Survos\FolioBundle\Menu\RowMenu;
use Survos\FolioBundle\Controller\{FolioAiController,FolioCollectionController,FolioController,FolioSearchController};
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;
use Survos\FolioBundle\Repository\{CoreRepository,FolioRepository,LinkRepository,LinkTypeRepository,RowRepository,StrRepository,StrTranslationRepository,TermRepository,TermSetRepository};
use Survos\FolioBundle\Service\{FolioAiArtifactPaths,FolioAiBatchPreparer,FolioAiClaimImporter,FolioAiPromptBuilder,FolioArchivePreparer,FolioArchiveService,FolioMeiliBuildSetCommand,FolioMeiliDocumentBuilder,FolioMeiliIndexer,FolioChatContextHolder,FolioChatPromptSuggester,FolioChatService,FolioChatTools,FolioDocsBuilder,FolioDtoTypeResolver,FolioFtsIndexer,FolioIngestService,FolioQueryAnalyzer,FolioRegistry,FolioRetriever,FolioSchemaManager,FolioSchemaSnapshotter,FolioService,FolioSlugResolverInterface,FolioViewBuilder,FolioSummaryService,FolioWordCloudService,RowClaimsResolver,RowTermsResolver};
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Survos\FolioBundle\State\FolioRowProvider;
use Survos\Kit\AbstractUxBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\{ContainerBuilder,ContainerInterface,Reference};
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

#[RequiredBundle(SurvosKitBundle::class)]
#[RequiredBundle(SurvosIiifBundle::class, ignoreOnInvalid: true)]
#[RequiredBundle(SurvosImgproxyBundle::class, ignoreOnInvalid: true)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosFolioBundle extends AbstractUxBundle
{
    use HasConfigurableRoutes;

    protected function twigNamespace(): ?string
    {
        return 'SurvosFolioBundle';
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $children = $definition->rootNode()->children();
        $this->addRouteOptions($children, '/folio');
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
            ->scalarNode('folio_server_route_prefix')
                ->info('The REMOTE folio_server\'s own route_prefix (e.g. zm\'s "/f") — used only to build folio:build\'s browse/search links. Deliberately separate from this app\'s own `route_prefix`: a producer app (folio_server set, routes_enabled false) has no reliable way to know the remote host\'s prefix from its own config, and the two are not guaranteed to match. Null falls back to this app\'s own `route_prefix`, which is only correct when folio_server\'s prefix happens to be the same.')
                ->defaultNull()
            ->end()
            ->scalarNode('folio_server_locale_prefix')
                ->info('Set when folio_server ALSO has `locale_prefix: true` (e.g. zm), so its folio routes are `/{_locale}/f/{folioCode}`, not just `/f/{folioCode}` — a browse link missing the locale segment 404s. Value is the locale to link to (e.g. "en"); null omits the segment entirely.')
                ->defaultNull()
            ->end()
            ->scalarNode('search_route')
                ->info('Host-app route that lists/searches datasets (e.g. zm\'s `app_search`). Set to enable provider breadcrumb links; null leaves the provider as plain text.')
                ->defaultNull()
            ->end()
            ->scalarNode('search_provider_param')
                ->info('Query param the search_route reads to pre-select a provider/aggregator facet.')
                ->defaultValue('dataset_aggregator')
            ->end()
            ->scalarNode('bookmark_class')
                ->info('Host app\'s concrete Bookmark entity (extends Survos\FolioBundle\Bookmark\Entity\BookmarkBase). Set alongside folder_class to enable BookmarkManager. See docs/bookmarks.md.')
                ->defaultNull()
            ->end()
            ->scalarNode('folder_class')
                ->info('Host app\'s concrete Folder entity (extends Survos\FolioBundle\Bookmark\Entity\FolderBase).')
                ->defaultNull()
            ->end()
            ->scalarNode('base_template')
                ->info('Layout every folio-bundle page (map.html.twig, search.html.twig, detail.html.twig, …) extends. Set to your own app chrome, e.g. "tenant/base.html.twig", so folio-bundle pages reached directly (not wrapped by a host controller) still get your navbar/logo instead of this bundle\'s bare fallback. Null uses "base.html.twig" resolved from the host app\'s own template root.')
                ->defaultNull()
            ->end()
            ->booleanNode('search_title_sort_enabled')
                ->info('FolioRowSearch\'s Title A-Z/Z-A sort options. Off for collections where titles are sparse/generic and year is the meaningful ordering (e.g. photo archives).')
                ->defaultTrue()
            ->end()
            ->scalarNode('search_default_sort')
                ->info('FolioRowSearch\'s default sort key, e.g. "year:asc" — must match a sort this class actually offers or it\'s silently ignored. Null keeps the historical default (whichever sort is added first — Title A-Z when search_title_sort_enabled).')
                ->defaultNull()
            ->end()
        ->end();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $this->addRouteLoaderCompilerPass($container);
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $this->captureRouteConfig($config);
        $builder->setParameter('survos_folio.folio_server', $config['folio_server']);
        // Expose the route prefix so client URL-builders (folio:pull download/list.json URLs,
        // folio:build browse/search links) match the SERVED routes instead of hardcoding "/folio".
        // zm configures this to "/f"; a mismatch 404s folio:pull.
        $builder->setParameter('survos_folio.route_prefix', $config['route_prefix']);
        $services = $container->services();
        foreach ([FolioRepository::class, CoreRepository::class, RowRepository::class, TermSetRepository::class, TermRepository::class, LinkTypeRepository::class, LinkRepository::class, StrRepository::class, StrTranslationRepository::class] as $class) {
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
        $services->set(RowTermsResolver::class)->autowire()->autoconfigure()->public();
        $services->set(RowClaimsResolver::class)->autowire()->autoconfigure()->public();
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
            $services->set(FolioMeiliBuildSetCommand::class)->autowire()->autoconfigure()->public();
        }
        // The 'folio_row' search this bundle's own search.html.twig already assumes exists
        // (hardcodes name: 'folio_row' + hitTemplate: 'search/hits/folio_row.html.twig') --
        // shared here rather than every host app hand-writing an identical class. Requires the
        // host app to composer require tacman/ux-search + survos/search-bundle, configure a
        // 'folio_fts' adapter (mezcalito_ux_search.yaml: folio_fts: 'sqlite-fts5://folio'), and
        // provide its own templates/search/hits/folio_row.html.twig (the hit card's links/chrome
        // are legitimately app-specific, unlike the facet/sort logic this class owns).
        if (class_exists(\Mezcalito\UxSearchBundle\Search\AbstractSearch::class) && interface_exists(\Survos\SearchBundle\Search\HitTemplateSearchInterface::class)) {
            $services->set(\Survos\FolioBundle\Search\FolioRowSearch::class)->autowire()->autoconfigure()->public()->args([
                '$titleSortEnabled' => $config['search_title_sort_enabled'],
                '$defaultSort' => $config['search_default_sort'],
            ]);
        }
        $services->set(FolioService::class)->autowire()->autoconfigure()->public()->args([
            '$folioEntityManager' => new Reference(sprintf('doctrine.orm.%s_entity_manager', $config['entity_manager'])),
            '$extension' => $config['extension'],
        ]);
        $services->set(FolioBuildCommand::class)->autowire()->autoconfigure()->public()->args([
            '$folioServer' => $config['folio_server'],
            '$routePrefix' => $config['route_prefix'],
            '$folioServerRoutePrefix' => $config['folio_server_route_prefix'],
            '$folioServerLocalePrefix' => $config['folio_server_locale_prefix'],
            '$kernelDebug' => '%kernel.debug%',
        ]);
        // Same "null if the optional collaborator isn't installed" pattern as FolioRegistry above
        // — a bare app with folio-bundle but not dataset-bundle/lingua-bundle gets folio:translate
        // registered but erroring clearly at runtime, not a container compile failure.
        $services->set(FolioTranslateCommand::class)->autowire()->autoconfigure()->public()->args([
            '$intl' => service(\Survos\DatasetBundle\Service\DatasetIntlService::class)->ignoreOnInvalid(),
            '$datasets' => service(\Survos\DatasetBundle\Repository\DatasetInfoRepository::class)->ignoreOnInvalid(),
            '$dataPaths' => service(\Survos\DatasetBundle\Service\DataPaths::class)->ignoreOnInvalid(),
        ]);
        foreach ([FolioMigrateCommand::class, FolioIngestCommand::class, FolioInfoCommand::class, FolioBrowseCommand::class, FolioFtsRebuildCommand::class, FolioArchiveCommand::class, FolioRestoreCommand::class, FolioPublishCommand::class, FolioPullCommand::class, FolioDtoTypeResolver::class] as $class) {
            $services->set($class)->autowire()->autoconfigure()->public();
        }
        $services->set(BuildFolioRequestedListener::class)->autowire()->autoconfigure()->public()
            ->arg('$buildArchive', $config['build_archive']);
        // No implementation required — a bare app without a slug registry just gets slug
        // routes that 404 (see FolioRouteAttributeListener), and its direct {folioCode}
        // routes keep working unchanged.
        $services->set(FolioRouteAttributeListener::class)->autowire()->autoconfigure()->public()
            ->arg('$slugResolver', service(FolioSlugResolverInterface::class)->ignoreOnInvalid());
        $services->set(\Survos\FolioBundle\Twig\FolioCoreTwig::class)->autowire()->autoconfigure()->public()
            ->arg('$searchRoute', $config['search_route'])
            ->arg('$searchProviderParam', $config['search_provider_param'])
            ->arg('$bookmarksEnabled', $config['bookmark_class'] !== null && $config['folder_class'] !== null)
            ->arg('$baseTemplate', $config['base_template']);
        $services->set(\Survos\FolioBundle\Service\FolioTimelineStats::class)->autowire()->autoconfigure()->public();
        $services->set(\Survos\FolioBundle\Twig\FolioTimelineTwig::class)->autowire()->autoconfigure()->public();
        if ($config['routes_enabled']) {
            foreach ([FolioCollectionController::class, FolioController::class, FolioSearchController::class] as $class) {
                $services->set($class)->autowire()->autoconfigure()->public();
            }
            // imgproxy-bundle is optional → pass null when absent (controller skips url unwrapping).
            $services->set(FolioAiController::class)->autowire()->autoconfigure()->public()
                ->arg('$imgproxy', new Reference(ImgproxyUrlBuilder::class, ContainerInterface::NULL_ON_INVALID_REFERENCE));
        }
        if (interface_exists(\ApiPlatform\State\ProviderInterface::class)) {
            $services->set(FolioRowProvider::class)->autowire()->autoconfigure()->public();
        }
        if ($config['admin_navbar_menu'] && class_exists(\Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber::class)) {
            $services->set(FolioMenu::class)->autowire()->autoconfigure()->public()->args([
                '$folioServer' => $config['folio_server'],
                '$routePrefix' => $config['route_prefix'],
            ]);
        }
        // Unconditional (unlike the ADMIN_NAVBAR_MENU FolioMenu above) — PAGE_ACTIONS on the
        // public row-scoped pages, not admin-gated. MenuBuilderTrait::add()'s checkRouteExists
        // makes app-specific items (Edit, Bookmark) safely absent in apps that don't define
        // those routes, so every folio-bundle app gets this by default with no config needed.
        // (trait_exists(), not class_exists() — MenuBuilderTrait is a trait; class_exists()
        // always returns false for it and silently drops this registration.)
        if (trait_exists(\Survos\TablerBundle\Menu\MenuBuilderTrait::class)) {
            $services->set(RowMenu::class)->autowire()->autoconfigure()->public();
        }
        // Same "null if the optional collaborator isn't configured" pattern as FolioRegistry
        // above — a host app that hasn't set bookmark_class/folder_class simply doesn't get
        // this service, rather than a container compile failure.
        if ($config['bookmark_class'] !== null && $config['folder_class'] !== null) {
            $services->set(BookmarkManager::class)->autowire()->autoconfigure()->public()->args([
                '$bookmarkClass' => $config['bookmark_class'],
                '$folderClass' => $config['folder_class'],
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
        parent::prependExtension($container, $builder);

        $entityDir = dirname(__DIR__) . '/src/Entity';
        if ($builder->hasExtension('api_platform')) {
            $builder->prependExtensionConfig('api_platform', ['mapping' => ['paths' => [$entityDir]]]);
        }
    }

}
