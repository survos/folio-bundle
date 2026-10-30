<?php

declare(strict_types=1);

namespace Survos\FolioBundle;

use Survos\BookmarkBundle\Service\BookmarkManager;
use Survos\FolioBundle\Catalog\DatasetPublicationClient;
use Survos\FolioBundle\Catalog\FolioCatalogClient;
use Survos\FolioBundle\Command\FolioBrowseCommand;
use Survos\FolioBundle\Command\FolioFtsRebuildCommand;
use Survos\FolioBundle\Command\FolioInfoCommand;
use Survos\FolioBundle\Command\FolioMigrateCommand;
use Survos\FolioBundle\Command\FolioPublishCommand;
use Survos\FolioBundle\Command\FolioPullCommand;
use Survos\FolioBundle\Command\FolioRestoreCommand;
use Survos\FolioBundle\Command\FolioSitemapCommand;
use Survos\FolioBundle\Controller\FolioAiController;
use Survos\FolioBundle\Controller\FolioController;
use Survos\FolioBundle\Controller\FolioSearchController;
use Survos\FolioBundle\DependencyInjection\DefaultDataPathsPass;
use Survos\FolioBundle\EventListener\FolioFtsIndexListener;
use Survos\FolioBundle\EventListener\FolioRouteAttributeListener;
use Survos\FolioBundle\Menu\FolioMenu;
use Survos\FolioBundle\Menu\FolioNavbarMenu;
use Survos\FolioBundle\Menu\RowMenu;
use Survos\FolioBundle\Publisher\PublisherApiClient;
use Survos\FolioBundle\Publisher\PublisherRegistrar;
use Survos\FolioBundle\Publisher\PublisherSelection;
use Survos\FolioBundle\Repository\CoreRepository;
use Survos\FolioBundle\Repository\FolioRepository;
use Survos\FolioBundle\Repository\LinkRepository;
use Survos\FolioBundle\Repository\LinkTypeRepository;
use Survos\FolioBundle\Repository\RowRepository;
use Survos\FolioBundle\Repository\StrRepository;
use Survos\FolioBundle\Repository\StrTranslationRepository;
use Survos\FolioBundle\Repository\TermRepository;
use Survos\FolioBundle\Repository\TermSetRepository;
use Survos\FolioBundle\Service\FolioAiArtifactPaths;
use Survos\FolioBundle\Service\FolioAiBatchPreparer;
use Survos\FolioBundle\Service\FolioAiClaimImporter;
use Survos\FolioBundle\Service\FolioAiPromptBuilder;
use Survos\FolioBundle\Service\FolioArchivePreparer;
use Survos\FolioBundle\Service\FolioArchiveService;
use Survos\FolioBundle\Service\FolioChatContextHolder;
use Survos\FolioBundle\Service\FolioChatPromptSuggester;
use Survos\FolioBundle\Service\FolioChatService;
use Survos\FolioBundle\Service\FolioChatTools;
use Survos\FolioBundle\Service\FolioDocsBuilder;
use Survos\FolioBundle\Service\FolioDtoTypeResolver;
use Survos\FolioBundle\Service\FolioElasticBuildSetCommand;
use Survos\FolioBundle\Service\FolioFacetFieldResolver;
use Survos\FolioBundle\Service\FolioFtsIndexer;
use Survos\FolioBundle\Service\FolioIngestService;
use Survos\FolioBundle\Service\FolioMeiliBuildSetCommand;
use Survos\FolioBundle\Service\FolioMeiliDocumentBuilder;
use Survos\FolioBundle\Service\FolioMeiliIndexer;
use Survos\FolioBundle\Service\FolioQueryAnalyzer;
use Survos\FolioBundle\Service\FolioRetriever;
use Survos\FolioBundle\Service\FolioSchemaManager;
use Survos\FolioBundle\Service\FolioSchemaSnapshotter;
use Survos\FolioBundle\Service\FolioService;
use Survos\FolioBundle\Service\FolioSlugResolverInterface;
use Survos\FolioBundle\Service\FolioSummaryService;
use Survos\FolioBundle\Service\FolioTermCloudService;
use Survos\FolioBundle\Service\FolioViewBuilder;
use Survos\FolioBundle\Service\FolioWordCloudService;
use Survos\FolioBundle\Service\RowClaimsResolver;
use Survos\FolioBundle\Service\RowSchemaOrgBuilder;
use Survos\FolioBundle\Service\RowTermsResolver;
use Survos\FolioBundle\Sitemap\FolioSitemapPopulator;
use Survos\FolioBundle\Sitemap\FolioSitemapRegistry;
use Survos\FolioBundle\State\FolioRowProvider;
use Survos\IiifBundle\SurvosIiifBundle;
use Survos\ImageContracts\ImageUrlBuilderInterface;
use Survos\ImagorBundle\SurvosImagorBundle;
use Survos\ImgproxyBundle\SurvosImgproxyBundle;
use Survos\Kit\AbstractUxBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

#[RequiredBundle(SurvosKitBundle::class)]
#[RequiredBundle(SurvosIiifBundle::class, ignoreOnInvalid: true)]
#[RequiredBundle(SurvosImgproxyBundle::class, ignoreOnInvalid: true)]
#[RequiredBundle(SurvosImagorBundle::class, ignoreOnInvalid: true)]
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
            ->booleanNode('folio_navbar_menu')->defaultTrue()
                ->info('Set false to drop the per-folio navbar menu (Search, Gallery, Map, Timeline, Chat, Term Cloud) and register your own.')
            ->end()
            ->integerNode('archive_compression_level')->min(0)->max(9)->defaultValue(1)->end()
            ->booleanNode('duckdb_browse')->defaultFalse()->info('Enable the optional DuckDB folio reader routes; ES routes are unchanged.')->end()
            ->arrayNode('duckdb_sources')->useAttributeAsKey('folioCode')->scalarPrototype()->end()->defaultValue([])->end()
            ->booleanNode('read_only')->defaultFalse()->info('Open published folios without schema updates, writes or journal-mode changes.')->end()
            ->scalarNode('archive_api_prefix')->defaultValue('/folio')->info('Remote archive API prefix, independent of browse routes.')->end()
            ->scalarNode('extension')->defaultValue('folio')->end()
            ->integerNode('catalog_ttl')
                ->defaultValue(300)
                ->info('Seconds a fetched hub catalog stays fresh before FolioCatalogClient refetches. The cached copy is also the outage fallback, so this is a refresh interval, not an expiry.')
            ->end()
            ->arrayNode('dataset_api')->canBeEnabled()->children()
                ->scalarNode('server')->defaultNull()->end()
                ->scalarNode('token')->defaultValue('')->end()
            ->end()->end()
            ->arrayNode('publisher')->canBeEnabled()
                ->info('This app publishes folios publicly and confirms them to Harvest (PublisherRegistrar). Uses dataset_api.server; see docs/publisher-registration.md.')
                ->children()
                    ->scalarNode('code')->isRequired()->info('Publisher identifier in Harvest HARVEST_PUBLISHER_TOKENS, e.g. ink, zm, vox.')->end()
                    ->scalarNode('environment')->defaultValue('prod')->info('Deployment (prod, local, …); each has its own generation.')->end()
                    ->scalarNode('token')->defaultValue('')->info('This publisher\'s token, not HARVEST_READ_TOKEN.')->end()
                    ->scalarNode('label')->defaultNull()->end()
                    ->scalarNode('base_url')->defaultNull()->info('When set, Harvest (and the client) reject confirmed URLs on another host.')->end()
                    ->variableNode('selection')->defaultValue(['mode' => 'all'])->info('Intent sent on reset: {mode: all} | {mode: tags, tags: [...]} | {mode: datasets, datasetKeys: [...]} | {mode: manual}.')->end()
                ->end()
            ->end()
            ->booleanNode('fts_enabled')
                ->defaultFalse()
                ->info('Build the per-file SQLite FTS5 index (item_fts) when a folio is built or inflated. Off by default: search is Elasticsearch (Harvest writes folio_item/folio_text), and the FTS pass re-tokenizes every row, which is slow and large on text-heavy folios. Set true for an app that still serves text search from the folio file itself; `folio:fts:rebuild --force` builds one on demand regardless.')
            ->end()
            ->integerNode('fts_max_rows')
                ->defaultValue(0)
                ->info('Rows up to which a folio is given a SQLite FTS index EVEN THOUGH its dataset opted out (extras.search: backend elasticsearch + allowFtsSkip — see docs/search-policy.md); past it, the opt-out is honored and no index is built. 0 = always honor it. A dataset that has not opted out is always indexed whatever its size, since no search at all is worse than a slow build.')
            ->end()
            ->integerNode('live_facet_max_rows')
                ->defaultValue(500000)
                ->info('Rows past which a text (FTS) search skips live facet counts, which are aggregated over the matching rows and cannot use the precomputed table. Measured on news/rappnews4909 (966,590 rows): ~5s per first-seen query, essentially all of it facets, against ~1s for hits and counts. Filter-only queries keep their facets either way. 0 = always live.')
            ->end()
            ->booleanNode('elastic_auto_index')->defaultFalse()
                ->info('Producer only: queue indexing + verification of every published Folio into the shared search indexes. Enable in Harvest, leave disabled in reader apps.')
            ->end()
            ->arrayNode('search')
                ->info('The shared Elasticsearch search indexes every Folio row lands in once (docs/search-indexes.md). Not app-prefixed: Harvest writes them, every app reads them. Needs ELASTICSEARCH_DSN.')
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('item_index')->defaultValue('folio_item')->end()
                    ->scalarNode('text_index')->defaultValue('folio_text')->end()
                    ->scalarNode('meta_index')->defaultValue('folio_meta')->end()
                    ->arrayNode('text_cores')->scalarPrototype()->end()->defaultValue(['article'])
                        ->info('Cores whose rows are text units (go to text_index, with parentRowId); every other core goes to item_index.')
                    ->end()
                    ->integerNode('shards')->defaultValue(1)->info('Primary shards of item_index and text_index, set when they are created.')->end()
                    ->integerNode('replicas')->defaultValue(0)->end()
                    ->integerNode('match_limit')->defaultValue(1000)
                        ->info('Row ids a text query brings back for a folio without item_fts; SQLite then pages and facets over them.')
                    ->end()
                ->end()
            ->end()
            ->scalarNode('entity_manager')->defaultValue('folio')->end()
            ->scalarNode('folio_server')
                ->info('Base URL of the live folio site — hosts the full folio UX and the folio archive API. Used for browse links and, when set, as folio:pull\'s preferred source (GET <server>/folio/list.json). Null by default so folio:pull reads the folio_archive storage, which is where the archives actually live (S3, via the folio-archive mount); an app that really does have a folio API sets this itself.')
                ->defaultNull()
            ->end()
            ->scalarNode('reader_server')->defaultNull()
                ->info('Optional specialist reader exposing /folios.json; only available folios receive reader links.')
            ->end()
            ->scalarNode('reader_proxy')->defaultNull()->end()
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
            ->arrayNode('search_hit_fields')
                ->info('Extra dto_data fields FolioRowSearch returns on every hit, for an app\'s own hit template (e.g. [date, frequency, digitizedUrls]). Each becomes hit.<field>; arrays and objects arrive as JSON strings.')
                ->scalarPrototype()->end()
                ->defaultValue([])
            ->end()
            ->scalarNode('data_dir')
                ->info('Root of the data tree; folios live in its folio/ directory. Used only when the application registers no DataPaths of its own (Harvest does, from dataset-bundle).')
                ->defaultValue('%env(APP_DATA_DIR)%')
                ->cannotBeEmpty()
            ->end()
            ->booleanNode('local_passthrough')
                ->info('folio:pull (and tenants:load, which delegates to it): when the target .folio already exists at the local Artifact path, skip the HTTP/storage fetch entirely — even under --force/--refresh. Opt-in: only correct when this app and the folio-building app share APP_DATA_DIR on the same filesystem (e.g. fotostory + md both mounting the same /platform volume); on a genuinely separate deployment a stale/wrong local file would silently never refresh.')
                ->defaultFalse()
            ->end()
            ->enumNode('folio_sets_source')
                ->info('Resolve membership from the configured published folio catalog.')
                ->values(['auto', 'catalog'])
                ->defaultValue('auto')
            ->end()
            ->arrayNode('folio_sets')
                ->normalizeKeys(false)
                ->info('Named sets of folios this app shows, selected by criteria over their metadata (a tag, set in harvest) or by folio name. Resolved by folio:sets:sync (run it as a composer auto-script); membership is derived and rebuilt every run. See docs/folio-sets.md.')
                ->useAttributeAsKey('code')
                ->arrayPrototype()
                    ->children()
                        ->scalarNode('label')->defaultNull()->end()
                        ->scalarNode('core')->defaultValue('obj')->end()
                        ->arrayNode('criteria')->isRequired()
                            ->children()
                                ->arrayNode('tags')->info('Any of these dataset tags')->scalarPrototype()->end()->end()
                                ->arrayNode('tagsAll')->info('All of these dataset tags')->scalarPrototype()->end()->end()
                                ->arrayNode('provider')->info('Any of these providers')->scalarPrototype()->end()->end()
                                ->arrayNode('contentType')->info('Any of these content types (newspaper, photograph, ...)')->scalarPrototype()->end()->end()
                                ->integerNode('minRows')->defaultValue(1)->end()
                                ->arrayNode('folios')->info('These folios by name (provider/code), in addition to any the criteria above select. For the one- or few-folio set; anything that should grow on its own is a tag.')->scalarPrototype()->end()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->arrayNode('sites')
                ->normalizeKeys(false)
                ->info('Hosts and the folio sets they show. A site has no criteria of its own: its folios are the union of its sets\' members, and a site that adds up to one folio is that folio\'s own site. See docs/folio-sets.md.')
                ->useAttributeAsKey('code')
                ->arrayPrototype()
                    ->children()
                        ->arrayNode('hosts')->isRequired()->requiresAtLeastOneElement()->scalarPrototype()->end()->end()
                        ->arrayNode('sets')->isRequired()->requiresAtLeastOneElement()->scalarPrototype()->end()->end()
                        ->booleanNode('restrict')->info('404 any folio outside this site\'s sets, as a one-collection site (tobacco) wants.')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end()
        ->end();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $this->addRouteLoaderCompilerPass($container);
        // Before autowiring resolves FolioService's DataPaths argument.
        $container->addCompilerPass(new DefaultDataPathsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 100);
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $this->captureRouteConfig($config);
        $builder->setParameter('survos_folio.folio_server', $config['folio_server']);
        // Local browse routes and remote archive API routes are independent.
        $builder->setParameter('survos_folio.route_prefix', $config['route_prefix']);
        $builder->setParameter('survos_folio.archive_api_prefix', $config['archive_api_prefix']);
        $builder->setParameter('survos_folio.local_passthrough', $config['local_passthrough']);
        $builder->setParameter('survos_folio.data_dir', $config['data_dir']);
        $services = $container->services();

        // The one hub-catalog reader (src/Catalog). Registered unconditionally: it is HTTP only,
        // needs no registry, and replacing each app's own copy is the point of it existing.
        // folio_server is where a reader already points for folio:pull, so the catalog follows it.
        if ($config['dataset_api']['enabled']) {
            $services->set(DatasetPublicationClient::class)->autowire()->autoconfigure()->args([
                '$server' => $config['dataset_api']['server'], '$token' => $config['dataset_api']['token'],
            ]);
        }
        if ($config['publisher']['enabled']) {
            if (!$config['dataset_api']['enabled']) {
                throw new \LogicException('survos_folio.publisher requires survos_folio.dataset_api (its server).');
            }
            $publisher = $config['publisher'];
            $services->set(PublisherApiClient::class)->autowire()->args([
                '$server' => $config['dataset_api']['server'], '$token' => $publisher['token'],
                '$publisher' => $publisher['code'], '$environment' => $publisher['environment'],
            ]);
            $services->set(PublisherRegistrar::class)->autowire()->public()->args([
                '$selection' => inline_service(PublisherSelection::class)->factory([PublisherSelection::class, 'fromArray'])->args([$publisher['selection']]),
                '$label' => $publisher['label'], '$baseUrl' => $publisher['base_url'],
                '$clock' => service('clock')->nullOnInvalid(),
            ]);
        }
        $services->set(FolioCatalogClient::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->args([
                '$server' => $config['folio_server'] ?? '',
                '$cacheFile' => '%kernel.project_dir%/var/catalog/folio-catalog.json',
                '$ttl' => $config['catalog_ttl'],
                '$datasets' => $config['dataset_api']['enabled'] ? service(DatasetPublicationClient::class) : null,
            ]);

        foreach ([FolioRepository::class, CoreRepository::class, RowRepository::class, TermSetRepository::class, TermRepository::class, LinkTypeRepository::class, LinkRepository::class, StrRepository::class, StrTranslationRepository::class] as $class) {
            $services->set($class)->autowire()->autoconfigure()->public()->tag('doctrine.repository_service');
        }
        // cache.system, not cache.app: the schema fingerprint is build-scoped derived data, so it
        // belongs in the build dir where cache:clear (i.e. every deploy) wipes it.
        $services->set(FolioSchemaManager::class)->autowire()->autoconfigure()->public()
            ->arg('$cache', service('cache.system'));
        $services->set(FolioSummaryService::class)->autowire()->autoconfigure()->public();
        $services->set(FolioFtsIndexer::class)->autowire()->autoconfigure()->public()
            ->arg('$maxRows', $config['fts_max_rows'])
            ->arg('$enabled', $config['fts_enabled']);
        $services->set(FolioQueryAnalyzer::class)->autowire()->autoconfigure()->public();
        $services->set(FolioRetriever::class)->autowire()->autoconfigure()->public();
        $services->set(FolioChatContextHolder::class)->autowire()->autoconfigure()->public();
        // autoconfigure() applies the ai-bundle's #[AsTool] autoconfiguration, tagging this ai.tool.
        $services->set(FolioChatTools::class)->autowire()->autoconfigure()->public();
        $services->set(FolioWordCloudService::class)->autowire()->autoconfigure()->public();
        $services->set(FolioFacetFieldResolver::class)->autowire()->autoconfigure()->public();
        $services->set(FolioTermCloudService::class)->autowire()->autoconfigure()->public();
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
        $services->set(FolioArchiveService::class)->autowire()->autoconfigure()->public()
            ->arg('$compressionLevel', $config['archive_compression_level']);
        $services->set(FolioIngestService::class)->autowire()->autoconfigure()->public();
        $services->set(FolioAiArtifactPaths::class)->autowire()->autoconfigure()->public();
        $services->set(FolioAiPromptBuilder::class)->autowire()->autoconfigure()->public();
        $services->set(FolioAiBatchPreparer::class)->autowire()->autoconfigure()->public();
        $services->set(FolioAiClaimImporter::class)->autowire()->autoconfigure()->public();
        // Engine-neutral: the Meili commands below and an app's Elasticsearch search both read
        // folio rows through FolioDocumentStream.
        $services->set(FolioMeiliDocumentBuilder::class)->autowire()->autoconfigure()->public();
        $services->set(Service\FolioDocumentStream::class)->autowire()->autoconfigure()->public();
        if (class_exists(\Survos\MeiliBundle\Service\MeiliService::class)) {
            $services->set(FolioMeiliIndexer::class)->autowire()->autoconfigure()->public();
            $services->set(FolioMeiliBuildSetCommand::class)->autowire()->autoconfigure()->public();
        }
        // The Elasticsearch twin, registered unconditionally: it talks to the cluster over plain
        // HTTP through the bundle's existing symfony/http-client, so unlike the Meili commands it
        // needs no engine client installed and nothing to guard on. It errors clearly when
        // ELASTICSEARCH_DSN is unset, which is the right failure for an app that has no cluster.
        $services->set(Service\FolioElasticClient::class)->autowire()->autoconfigure();
        $services->set(FolioElasticBuildSetCommand::class)->autowire()->autoconfigure()->public();
        // The shared search indexes every published folio lands in (docs/search-indexes.md), the
        // command that fills and verifies them, and — where Messenger exists — the message a publication queues.
        $services->set(Service\FolioSearchProjector::class)->autowire()->autoconfigure()->public()
            ->arg('$textCores', $config['search']['text_cores']);
        $services->set(Service\FolioSearchIndex::class)->autowire()->autoconfigure()->public()->args([
            '$itemIndex' => $config['search']['item_index'],
            '$textIndex' => $config['search']['text_index'],
            '$metaIndex' => $config['search']['meta_index'],
            '$shards' => $config['search']['shards'],
            '$replicas' => $config['search']['replicas'],
            '$matchLimit' => $config['search']['match_limit'],
        ]);
        $services->set(Service\FolioElasticIndexCommand::class)->autowire()->autoconfigure()->public();
        $services->set(EventListener\FolioElasticIndexListener::class)->autowire()->autoconfigure()
            ->arg('$enabled', $config['elastic_auto_index'])
            ->tag('kernel.event_listener', ['event' => 'Survos\FolioBundle\Event\FolioPublishedEvent']);
        if (interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            $services->set(MessageHandler\IndexFolioRowsHandler::class)->autowire()->autoconfigure();
        }
        // The 'folio_row' search this bundle's own search.html.twig already assumes exists
        // (hardcodes name: 'folio_row' + hitTemplate: 'search/hits/folio_row.html.twig') --
        // shared here rather than every host app hand-writing an identical class. Requires the
        // host app to composer require survos/search-bundle, configure a
        // 'folio_fts' adapter (survos_search.yaml: folio_fts: 'sqlite-fts5://folio'), and
        // provide its own templates/search/hits/folio_row.html.twig (the hit card's links/chrome
        // are legitimately app-specific, unlike the facet/sort logic this class owns).
        if (class_exists(\Survos\SearchBundle\Search\AbstractSearch::class) && interface_exists(\Survos\SearchBundle\Search\HitTemplateSearchInterface::class)) {
            $services->set(Search\FolioRowSearch::class)->autowire()->autoconfigure()->public()->args([
                '$titleSortEnabled' => $config['search_title_sort_enabled'],
                '$defaultSort' => $config['search_default_sort'],
                '$hitFields' => $config['search_hit_fields'],
                '$liveFacetMaxRows' => $config['live_facet_max_rows'],
            ]);
        }
        $services->set(Service\PeriodicalCoverageService::class)->autowire()->autoconfigure();
        $services->set(FolioService::class)->autowire()->autoconfigure()->public()->args([
            '$folioEntityManager' => new Reference(sprintf('doctrine.orm.%s_entity_manager', $config['entity_manager'])),
            '$extension' => $config['extension'],
            '$readOnly' => $config['read_only'],
        ]);
        $services->set(Service\FolioBuilder::class)->autowire()->autoconfigure()->public();
        $services->set(Service\DuckDbFolioExporter::class)->autowire()->autoconfigure()->public();
        $services->set(Service\DuckDbFolioPreparer::class)->autowire()->autoconfigure();
        $services->set(Service\DuckDbFolioSearchProjection::class)->autowire()->autoconfigure();
        $services->set(Service\DuckDbDtoSchema::class)->autowire()->autoconfigure();
        $services->set(Service\DuckDbFolioBuilder::class)->autowire()->autoconfigure()->public();
        $services->set(Service\DuckDbFolioReader::class)->autowire()->autoconfigure()->public();
        $services->set(Service\LocalDuckDbFolioLocator::class)->autowire()->arg('$sources', $config['duckdb_sources']);
        $services->alias(Service\DuckDbFolioLocatorInterface::class, Service\LocalDuckDbFolioLocator::class);
        $services->set(Service\DuckDbFolioConnection::class)->autowire()->autoconfigure()->arg('$enabled', $config['duckdb_browse']);
        $services->set(Service\DuckDbFolioView::class)->autowire();
        $services->set(Search\FolioDuckDbSearch::class)->autowire()->autoconfigure()->public();

        foreach ([Service\FolioMigrator::class, FolioMigrateCommand::class, FolioInfoCommand::class, FolioBrowseCommand::class, FolioFtsRebuildCommand::class, FolioRestoreCommand::class, FolioPublishCommand::class, FolioPullCommand::class, FolioDtoTypeResolver::class] as $class) {
            $services->set($class)->autowire()->autoconfigure()->public();
        }
        foreach ($config['sites'] as $siteCode => $site) {
            foreach ($site['sets'] as $setCode) {
                if (!isset($config['folio_sets'][$setCode])) {
                    throw new \InvalidArgumentException(sprintf('survos_folio.sites.%s shows set "%s", which is not in survos_folio.folio_sets.', $siteCode, $setCode));
                }
            }
        }
        $builder->setParameter('survos_folio.folio_sets', $config['folio_sets']);
        $builder->setParameter('survos_folio.sites', $config['sites']);
        $builder->setParameter('survos_folio.folio_sets_source', $config['folio_sets_source']);
        // Sets and sites: resolver, site registry and host listener, wired by their own attributes.
        $services->load('Survos\\FolioBundle\\Set\\', $this->bundleRootPath().'/src/Set/')->autowire()->autoconfigure();
        // No implementation required — a bare app without a slug registry just gets slug
        // routes that 404 (see FolioRouteAttributeListener), and its direct {folioCode}
        // routes keep working unchanged.
        $services->set(FolioRouteAttributeListener::class)->autowire()->autoconfigure()->public()
            ->arg('$slugResolver', service(FolioSlugResolverInterface::class)->ignoreOnInvalid());
        $services->set(Twig\FolioReaderCatalog::class)->autowire()->autoconfigure()
            ->arg('$server', $config['reader_server'])
            ->arg('$proxy', $config['reader_proxy'])
            ->arg('$datasets', $config['dataset_api']['enabled'] ? service(DatasetPublicationClient::class) : null);
        $services->set(Twig\FolioCoreTwig::class)->autowire()->autoconfigure()->public()
            ->arg('$searchRoute', $config['search_route'])
            ->arg('$searchProviderParam', $config['search_provider_param'])
            ->arg('$bookmarksEnabled', null !== $config['bookmark_class'] && null !== $config['folder_class'])
            ->arg('$baseTemplate', $config['base_template'])
            ->arg('$folioServer', $config['folio_server'])
            ->arg('$folioRoutePrefix', $config['folio_server_route_prefix'] ?? $config['route_prefix'])
            ->arg('$folioLocalePrefix', $config['folio_server_locale_prefix']);
        $services->set(Service\FolioTimelineStats::class)->autowire()->autoconfigure()->public();
        $services->set(Twig\FolioTimelineTwig::class)->autowire()->autoconfigure()->public();
        // |folio_image: imgproxy or imagor when installed, the plain URL when not (see FolioImageTwig).
        $services->set(Twig\FolioImageTwig::class)->autoconfigure()->args([
            '$imageUrls' => service(ImageUrlBuilderInterface::class)->ignoreOnInvalid(),
        ]);
        // JSON-LD for the row detail page. schema-org-bundle is optional: survos/data-contracts
        // already annotates its item DTOs with #[SchemaOrg]/#[SchemaProperty], but declares the
        // bundle as a `suggest` — the attributes are inert without it. Same shape here, so an
        // app that doesn't want structured data doesn't get the dependency.
        if (class_exists(\Survos\SchemaOrgBundle\Graph\SchemaOrgGraph::class)) {
            $services->set(RowSchemaOrgBuilder::class)->autowire()->autoconfigure()->public();
        }

        // Sitemaps need presta/sitemap-bundle. It is a `require` of this bundle, but that is NOT
        // enough to assume it is present: every consuming app resolves vendor/survos/folio-bundle
        // to a symlink into ~/sites/mono, so it runs this code against ITS OWN vendor tree and
        // never installs our composer requirements. harvest, ssai and fotostory all link this
        // bundle and none of them have presta -- so registering these unconditionally takes their
        // containers down at compile time with "cannot autowire $dumper".
        //
        // src/Sitemap/ is wired by hand, so it is enough not to register it. src/Command/ is
        // auto-scanned by the kit base (already done by parent::loadExtension() above), so
        // FolioSitemapCommand has to be actively removed instead.
        if (interface_exists(\Presta\SitemapBundle\Service\DumperInterface::class)) {
            $services->set(FolioSitemapRegistry::class)->autowire()->autoconfigure()->public();
            $services->set(FolioSitemapPopulator::class)->autowire()->autoconfigure()->public();
        } elseif ($builder->hasDefinition(FolioSitemapCommand::class)) {
            $builder->removeDefinition(FolioSitemapCommand::class);
        }
        if ($config['routes_enabled']) {
            $services->set(FolioSearchController::class)->autowire()->autoconfigure()->public();
            // $rowSchemaOrg null when schema-org-bundle isn't installed (the service above is
            // then never defined) — rowShow() skips the JSON-LD rather than failing to compile.
            $services->set(FolioController::class)->autowire()->autoconfigure()->public()
                ->arg('$rowSchemaOrg', service(RowSchemaOrgBuilder::class)->ignoreOnInvalid());
            // An image backend is optional → pass null when absent (controller skips url unwrapping).
            $services->set(FolioAiController::class)->autowire()->autoconfigure()->public()
                ->arg('$imageUrls', new Reference(ImageUrlBuilderInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE));
        }
        if (interface_exists(\ApiPlatform\State\ProviderInterface::class)) {
            $services->set(FolioRowProvider::class)->autowire()->autoconfigure()->public();
            $services->set(State\WallCardProvider::class)->autowire()->autoconfigure()->public();
            $services->set(Service\WallCardMapper::class)->autowire()->autoconfigure();
            $services->set(Service\WallCardQuery::class)->autowire()->autoconfigure();
            $services->set(Serializer\WallCardCollectionNormalizer::class)->autowire()->autoconfigure()
                ->tag('serializer.normalizer', ['priority' => 100]);
            $services->set(EventListener\WallCardResponseListener::class)->autowire()->autoconfigure();
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
        // Per-folio navbar menu, public like RowMenu. Overview/Schema inside it are admin-only.
        if ($config['folio_navbar_menu'] && trait_exists(\Survos\TablerBundle\Menu\MenuBuilderTrait::class)) {
            $services->set(FolioNavbarMenu::class)->autowire()->autoconfigure()->public();
        }
        // Same "null if the optional collaborator isn't configured" optional service pattern
        // above — a host app that hasn't set bookmark_class/folder_class simply doesn't get
        // this service, rather than a container compile failure.
        if (null !== $config['bookmark_class'] && null !== $config['folder_class']) {
            if (!class_exists(BookmarkManager::class)) {
                throw new \LogicException('Install survos/bookmark-bundle before configuring survos_folio.bookmark_class and folder_class.');
            }
            $services->set(BookmarkManager::class)->autowire()->autoconfigure()->public()->args([
                '$bookmarkClass' => $config['bookmark_class'],
                '$folderClass' => $config['folder_class'],
            ]);
            $services->set(Bookmark\Service\BookmarkManager::class)->autowire()->autoconfigure()->public()->args([
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

        $entityDir = dirname(__DIR__).'/src/Entity';
        if ($builder->hasExtension('api_platform')) {
            $builder->prependExtensionConfig('api_platform', ['mapping' => ['paths' => [$entityDir, dirname(__DIR__).'/src/Api']]]);
        }
        // FolioRowSearch (the search page) runs on the 'folio_fts' adapter: SQLite FTS5 inside the
        // folio itself. Every app used to add this identical line to survos_search.yaml; an app
        // can still override it there.
        if ($builder->hasExtension('survos_search')) {
            $builder->prependExtensionConfig('survos_search', ['adapters' => ['folio_fts' => 'sqlite-fts5://folio', 'folio_duckdb' => 'duckdb://folio']]);
        }
        // Row pages call ux_icon() with vocabulary codes (term sets 'pla', 'per', …). data-bundle
        // registers the same aliases, but folio-bundle does not require it.
        if ($builder->hasExtension('ux_icons')) {
            $builder->prependExtensionConfig('ux_icons', ['aliases' => \Survos\DataContracts\Vocabulary\MuseumVocab::ICONS]);
        }
    }
}
