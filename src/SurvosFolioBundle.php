<?php

declare(strict_types=1);

namespace Survos\FolioBundle;

use Survos\DataContracts\Path\DataPaths;
use Survos\FolioBundle\Catalog\FolioCatalogClient;
use Survos\IiifBundle\SurvosIiifBundle;
use Survos\ImgproxyBundle\SurvosImgproxyBundle;
use Survos\FolioBundle\Bookmark\Service\BookmarkManager;
use Survos\FolioBundle\Command\{FolioArchiveCommand,FolioBrowseCommand,FolioBuildCommand,FolioFtsRebuildCommand,FolioInfoCommand,FolioIngestCommand,FolioMigrateCommand,FolioPublishCommand,FolioPullCommand,FolioRestoreCommand,FolioTranslateCommand,FolioValidateCommand};
use Survos\FolioBundle\EventListener\{BuildFolioRequestedListener,FolioFtsIndexListener,FolioRouteAttributeListener};
use Survos\FolioBundle\Menu\FolioMenu;
use Survos\FolioBundle\Menu\RowMenu;
use Survos\FolioBundle\Controller\{FolioAiController,FolioCollectionController,FolioController,FolioSearchController};
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;
use Survos\FolioBundle\Repository\{CoreRepository,FolioRepository,LinkRepository,LinkTypeRepository,RowRepository,StrRepository,StrTranslationRepository,TermRepository,TermSetRepository};
use Survos\FolioBundle\Service\{FolioAiArtifactPaths,FolioAiBatchPreparer,FolioAiClaimImporter,FolioAiPromptBuilder,FolioArchivePreparer,FolioArchiveService,FolioElasticBuildSetCommand,FolioMeiliBuildSetCommand,FolioMeiliDocumentBuilder,FolioMeiliIndexer,FolioChatContextHolder,FolioChatPromptSuggester,FolioChatService,FolioChatTools,FolioDocsBuilder,FolioDtoTypeResolver,FolioFacetFieldResolver,FolioFtsIndexer,FolioIngestService,FolioQueryAnalyzer,FolioRegistry,FolioRetriever,FolioSchemaManager,FolioSchemaSnapshotter,FolioService,FolioSlugResolverInterface,FolioTermCloudService,FolioViewBuilder,FolioSummaryService,FolioWordCloudService,RowClaimsResolver,RowSchemaOrgBuilder,RowTermsResolver};
use Survos\FolioBundle\Command\FolioSitemapCommand;
use Survos\FolioBundle\Sitemap\FolioSitemapPopulator;
use Survos\FolioBundle\Sitemap\FolioSitemapRegistry;
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
            ->booleanNode('read_only')->defaultFalse()->info('Open published folios without schema updates, writes or journal-mode changes.')->end()
            ->scalarNode('archive_api_prefix')->defaultValue('/folio')->info('Remote archive API prefix, independent of browse routes.')->end()
            ->scalarNode('extension')->defaultValue('folio')->end()
            ->integerNode('catalog_ttl')
                ->defaultValue(300)
                ->info('Seconds a fetched hub catalog stays fresh before FolioCatalogClient refetches. The cached copy is also the outage fallback, so this is a refresh interval, not an expiry.')
            ->end()
            ->scalarNode('data_dir')
                ->defaultValue('%env(APP_DATA_DIR)%')
                ->info('Root of the data tree folio paths resolve under, same value and default as survos_dataset.data_dir. Only used when dataset-bundle is absent: when it is installed IT registers DataPaths, from its own (richer) path config, and this is ignored.')
            ->end()
            ->integerNode('fts_max_rows')
                ->defaultValue(0)
                ->info('Rows up to which a folio is given a SQLite FTS index EVEN THOUGH its dataset opted out (extras.search: backend elasticsearch + allowFtsSkip — see docs/search-policy.md); past it, the opt-out is honored and no index is built. 0 = always honor it. A dataset that has not opted out is always indexed whatever its size, since no search at all is worse than a slow build.')
            ->end()
            ->integerNode('live_facet_max_rows')
                ->defaultValue(500000)
                ->info('Rows past which a text (FTS) search skips live facet counts, which are aggregated over the matching rows and cannot use the precomputed table. Measured on news/rappnews4909 (966,590 rows): ~5s per first-seen query, essentially all of it facets, against ~1s for hits and counts. Filter-only queries keep their facets either way. 0 = always live.')
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
            ->booleanNode('local_passthrough')
                ->info('folio:pull (and tenants:load, which delegates to it): when the target .folio already exists at the local Artifact path, skip the HTTP/storage fetch entirely — even under --force/--refresh. Opt-in: only correct when this app and the folio-building app share APP_DATA_DIR on the same filesystem (e.g. fotostory + md both mounting the same /platform volume); on a genuinely separate deployment a stale/wrong local file would silently never refresh.')
                ->defaultFalse()
            ->end()
            ->arrayNode('folio_sets')
                ->info('Named sets of folios this app shows, each defined by criteria over the dataset registry, never by listing folios. Resolved by folio:sets:sync (run it as a composer auto-script); membership is derived and rebuilt every run. See docs/folio-sets.md.')
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
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->scalarNode('reviewed_translations_dir')
                ->info('Directory of hand-reviewed translation overrides, one <code.locale>.jsonl per dataset+locale (same {code,locale,text} shape dataset:intl:pull writes to the gitignored working trans/ dir) — but THIS directory is meant to be committed to the app\'s own repo, same convention as translations/messages.*.yaml. Checked by folio:build --locale, taking precedence over whatever Lingua returned for the same code: a small, human/AI-correctable vocabulary (dataset tags, term labels — hundreds of entries, not thousands) is worth reviewing once and keeping stable, rather than trusting a machine-translation engine\'s output verbatim forever. Null (default) disables the override entirely.')
                ->defaultNull()
            ->end()
        ->end();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $this->addRouteLoaderCompilerPass($container);
    }

    /**
     * Whether survos/dataset-bundle's production registry is installed.
     *
     * Cached in a property rather than recomputed so the bare-reader path is testable: this class
     * is final by design, so a test presets the property by reflection to assert both branches.
     * See tests/Bundle/DatasetRegistryOptionalTest.php.
     */
    private ?bool $datasetRegistryAvailable = null;

    private function hasDatasetRegistry(?ContainerBuilder $builder = null): bool
    {
        // What matters is whether THIS app ENABLED dataset-bundle, not whether the class is
        // reachable on the autoloader: an app that drops it from config/bundles.php still has it
        // in vendor/ (and every app symlinks it from ~/sites/mono, so it is always "installed"),
        // and in that state class_exists() says yes while the registry's services do not exist —
        // so anything registered on the strength of it fails to autowire and takes the container
        // down at compile time.
        //
        // kernel.bundles, not hasExtension(): loadExtension() runs against Symfony's temporary
        // MergeExtensionConfigurationContainerBuilder, which does not carry the other bundles'
        // extensions, so hasExtension('survos_dataset') is false even in harvest where the bundle
        // is very much enabled. The parameter bag IS proxied through, and kernel.bundles is set
        // before any extension loads.
        if ($this->datasetRegistryAvailable !== null) {
            return $this->datasetRegistryAvailable;
        }

        $bundles = $builder?->hasParameter('kernel.bundles') === true
            ? (array) $builder->getParameter('kernel.bundles')
            : [];

        return $this->datasetRegistryAvailable = isset($bundles['SurvosDatasetBundle'])
            || in_array(\Survos\DatasetBundle\SurvosDatasetBundle::class, $bundles, true);
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
        $builder->setParameter('survos_folio.reviewed_translations_dir', $config['reviewed_translations_dir']);
        $services = $container->services();

        // survos/dataset-bundle is a SUGGEST, not a require: it carries the production registry
        // (a second Doctrine connection, its entities and API Platform resources) that an app which
        // only displays folios has no business installing. Everything below that touches the
        // registry is registered only when it is actually present.
        //
        // class_exists, not hasExtension: every consuming app resolves vendor/survos/folio-bundle
        // to a symlink into ~/sites/mono and runs this code against ITS OWN vendor tree, so a
        // composer requirement never proved presence here anyway (same reasoning as the presta
        // guard below).
        $hasDatasetRegistry = $this->hasDatasetRegistry($builder);

        // DataPaths is the one piece of dataset-bundle a reader genuinely needs — it resolves every
        // folio path under APP_DATA_DIR. It lives in survos/data-contracts (a plain library) so a
        // bare app can have it without the registry; dataset-bundle registers the same class from
        // its own richer config, so only define it when nobody else has.
        if (!$hasDatasetRegistry) {
            $services->set(DataPaths::class)
                ->autowire()
                ->autoconfigure()
                ->public()
                ->args(['$dataDir' => $config['data_dir']]);
        }

        // The one hub-catalog reader (src/Catalog). Registered unconditionally: it is HTTP only,
        // needs no registry, and replacing each app's own copy is the point of it existing.
        // folio_server is where a reader already points for folio:pull, so the catalog follows it.
        $services->set(FolioCatalogClient::class)
            ->autowire()
            ->autoconfigure()
            ->public()
            ->args([
                '$server' => $config['folio_server'] ?? '',
                '$cacheFile' => '%kernel.project_dir%/var/catalog/folio-catalog.json',
                '$ttl' => $config['catalog_ttl'],
            ]);

        foreach ([FolioRepository::class, CoreRepository::class, RowRepository::class, TermSetRepository::class, TermRepository::class, LinkTypeRepository::class, LinkRepository::class, StrRepository::class, StrTranslationRepository::class] as $class) {
            $services->set($class)->autowire()->autoconfigure()->public()->tag('doctrine.repository_service');
        }
        // cache.system, not cache.app: the schema fingerprint is build-scoped derived data, so it
        // belongs in the build dir where cache:clear (i.e. every deploy) wipes it.
        $services->set(FolioSchemaManager::class)->autowire()->autoconfigure()->public()
            ->arg('$cache', service('cache.system'));
        $services->set(FolioRegistry::class)->autowire()->autoconfigure()->public()
            // Auto-wire the dataset registry EM only if dataset-bundle is loaded; null otherwise,
            // so a bare app can require folio-bundle, pull a folio, and display it — no dataset infra.
            ->arg('$datasetEntityManager', service('doctrine.orm.dataset_entity_manager')->ignoreOnInvalid());
        $services->set(FolioSummaryService::class)->autowire()->autoconfigure()->public();
        $services->set(FolioFtsIndexer::class)->autowire()->autoconfigure()->public()
            ->arg('$maxRows', $config['fts_max_rows']);
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
        $services->set(FolioArchiveService::class)->autowire()->autoconfigure()->public();
        $services->set(FolioIngestService::class)->autowire()->autoconfigure()->public();
        $services->set(FolioAiArtifactPaths::class)->autowire()->autoconfigure()->public();
        $services->set(FolioAiPromptBuilder::class)->autowire()->autoconfigure()->public();
        $services->set(FolioAiBatchPreparer::class)->autowire()->autoconfigure()->public();
        $services->set(FolioAiClaimImporter::class)->autowire()->autoconfigure()->public();
        // Engine-neutral: the Meili commands below and an app's Elasticsearch search both read
        // folio rows through FolioDocumentStream.
        $services->set(FolioMeiliDocumentBuilder::class)->autowire()->autoconfigure()->public();
        $services->set(\Survos\FolioBundle\Service\FolioDocumentStream::class)->autowire()->autoconfigure()->public();
        if (class_exists(\Survos\MeiliBundle\Service\MeiliService::class)) {
            $services->set(FolioMeiliIndexer::class)->autowire()->autoconfigure()->public();
            // $datasets nullable/optional -- same "erroring clearly at runtime, not a container
            // compile failure" pattern as FolioTranslateCommand's own registration below, for an
            // app with folio-bundle but not dataset-bundle. Used only for the best-effort
            // raw-index Meili locale hint (see the command's own resolveLocalizedAttributes()).
            $services->set(FolioMeiliBuildSetCommand::class)->autowire()->autoconfigure()->public()->args([
                '$datasets' => service(\Survos\DatasetBundle\Repository\DatasetInfoRepository::class)->ignoreOnInvalid(),
            ]);
        }
        // The Elasticsearch twin, registered unconditionally: it talks to the cluster over plain
        // HTTP through the bundle's existing symfony/http-client, so unlike the Meili commands it
        // needs no engine client installed and nothing to guard on. It errors clearly when
        // ELASTICSEARCH_DSN is unset, which is the right failure for an app that has no cluster.
        $services->set(FolioElasticBuildSetCommand::class)->autowire()->autoconfigure()->public();
        // The 'folio_row' search this bundle's own search.html.twig already assumes exists
        // (hardcodes name: 'folio_row' + hitTemplate: 'search/hits/folio_row.html.twig') --
        // shared here rather than every host app hand-writing an identical class. Requires the
        // host app to composer require survos/search-bundle, configure a
        // 'folio_fts' adapter (survos_search.yaml: folio_fts: 'sqlite-fts5://folio'), and
        // provide its own templates/search/hits/folio_row.html.twig (the hit card's links/chrome
        // are legitimately app-specific, unlike the facet/sort logic this class owns).
        if (class_exists(\Survos\SearchBundle\Search\AbstractSearch::class) && interface_exists(\Survos\SearchBundle\Search\HitTemplateSearchInterface::class)) {
            $services->set(\Survos\FolioBundle\Search\FolioRowSearch::class)->autowire()->autoconfigure()->public()->args([
                '$titleSortEnabled' => $config['search_title_sort_enabled'],
                '$defaultSort' => $config['search_default_sort'],
                '$hitFields' => $config['search_hit_fields'],
                '$liveFacetMaxRows' => $config['live_facet_max_rows'],
            ]);
        }
        $services->set(\Survos\FolioBundle\Service\PeriodicalCoverageService::class)->autowire()->autoconfigure();
        $services->set(FolioService::class)->autowire()->autoconfigure()->public()->args([
            '$folioEntityManager' => new Reference(sprintf('doctrine.orm.%s_entity_manager', $config['entity_manager'])),
            '$extension' => $config['extension'],
            '$readOnly' => $config['read_only'],
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
            '$dataPaths' => service(\Survos\DataContracts\Path\DataPaths::class)->ignoreOnInvalid(),
        ]);
        foreach ([FolioMigrateCommand::class, FolioIngestCommand::class, FolioInfoCommand::class, FolioBrowseCommand::class, FolioFtsRebuildCommand::class, FolioArchiveCommand::class, FolioRestoreCommand::class, FolioPublishCommand::class, FolioPullCommand::class, FolioDtoTypeResolver::class] as $class) {
            $services->set($class)->autowire()->autoconfigure()->public();
        }
        $services->set(\Survos\FolioBundle\Set\FolioSetResolver::class)->autowire()->public()->args([
            '$sets' => $config['folio_sets'],
            '$membershipDir' => '%kernel.project_dir%/var/folio-sets',
            '$datasets' => service(\Survos\DatasetBundle\Repository\DatasetInfoRepository::class)->ignoreOnInvalid(),
        ]);
        // Bare #[AsEventListener] (no event named): Symfony infers the event by reflecting on
        // __invoke(BuildFolioRequestedEvent), so registering this without dataset-bundle fails at
        // compile time. Nothing dispatches that event in a reader app anyway — it is how the
        // producer asks for a build.
        if ($hasDatasetRegistry) {
            $services->set(BuildFolioRequestedListener::class)->autowire()->autoconfigure()->public()
                ->arg('$buildArchive', $config['build_archive']);
        } else {
            $builder->removeDefinition(BuildFolioRequestedListener::class);
            // Producer commands: each one's job is to WRITE the registry (build a folio, archive
            // it, validate its rows, translate it). They are auto-scanned like the controllers, and
            // without dataset-bundle they can only fail confusingly part-way, so a reader app
            // should not be offered them at all. Pulling and displaying published folios —
            // folio:pull, folio:info, folio:browse, folio:migrate — stays available.
            foreach ([FolioBuildCommand::class, FolioArchiveCommand::class, FolioTranslateCommand::class, FolioValidateCommand::class] as $producerCommand) {
                $builder->removeDefinition($producerCommand);
            }
        }
        // No implementation required — a bare app without a slug registry just gets slug
        // routes that 404 (see FolioRouteAttributeListener), and its direct {folioCode}
        // routes keep working unchanged.
        $services->set(FolioRouteAttributeListener::class)->autowire()->autoconfigure()->public()
            ->arg('$slugResolver', service(FolioSlugResolverInterface::class)->ignoreOnInvalid());
        $services->set(\Survos\FolioBundle\Twig\FolioReaderCatalog::class)->autowire()->autoconfigure()
            ->arg('$server', $config['reader_server'])
            ->arg('$proxy', $config['reader_proxy']);
        $services->set(\Survos\FolioBundle\Twig\FolioCoreTwig::class)->autowire()->autoconfigure()->public()
            ->arg('$searchRoute', $config['search_route'])
            ->arg('$searchProviderParam', $config['search_provider_param'])
            ->arg('$bookmarksEnabled', $config['bookmark_class'] !== null && $config['folder_class'] !== null)
            ->arg('$baseTemplate', $config['base_template'])
            ->arg('$folioServer', $config['folio_server'])
            ->arg('$folioRoutePrefix', $config['folio_server_route_prefix'] ?? $config['route_prefix'])
            ->arg('$folioLocalePrefix', $config['folio_server_locale_prefix']);
        $services->set(\Survos\FolioBundle\Service\FolioTimelineStats::class)->autowire()->autoconfigure()->public();
        $services->set(\Survos\FolioBundle\Twig\FolioTimelineTwig::class)->autowire()->autoconfigure()->public();
        // |folio_image: imgproxy when installed, the plain URL when not (see FolioImageTwig).
        $services->set(\Survos\FolioBundle\Twig\FolioImageTwig::class)->autoconfigure()->args([
            '$imgproxy' => service('Survos\\ImgproxyBundle\\Service\\ImgproxyUrlBuilder')->ignoreOnInvalid(),
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
        if (interface_exists(\Presta\SitemapBundle\Service\DumperInterface::class) && $hasDatasetRegistry) {
            // FolioSitemapRegistry enumerates published Artifact rows, so it needs the registry as
            // well as presta. A reader app's sitemap comes from whatever it pulled, not from here.
            $services->set(FolioSitemapRegistry::class)->autowire()->autoconfigure()->public();
            $services->set(FolioSitemapPopulator::class)->autowire()->autoconfigure()->public();
        } elseif ($builder->hasDefinition(FolioSitemapCommand::class)) {
            $builder->removeDefinition(FolioSitemapCommand::class);
        }
        if ($config['routes_enabled']) {
            // FolioCollectionController browses the registry's Artifact rows (its action
            // type-hints ArtifactRepository, which the controller-argument pass reflects at
            // compile time). The collection index is a producer/hub view; a reader app reaches
            // folios by code, and its catalog comes from the hub over HTTP.
            $collectionControllers = $hasDatasetRegistry
                ? [FolioCollectionController::class, FolioSearchController::class]
                : [FolioSearchController::class];
            foreach ($collectionControllers as $class) {
                $services->set($class)->autowire()->autoconfigure()->public();
            }
            if (!$hasDatasetRegistry) {
                // src/Controller/ is auto-scanned by the kit base (parent::loadExtension above),
                // so leaving it out of the list is not enough — it has to be actively removed,
                // exactly like FolioSitemapCommand below.
                $builder->removeDefinition(FolioCollectionController::class);
            }
            // $rowSchemaOrg null when schema-org-bundle isn't installed (the service above is
            // then never defined) — rowShow() skips the JSON-LD rather than failing to compile.
            $services->set(FolioController::class)->autowire()->autoconfigure()->public()
                ->arg('$rowSchemaOrg', service(RowSchemaOrgBuilder::class)->ignoreOnInvalid());
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
        // FolioRowSearch (the search page) runs on the 'folio_fts' adapter: SQLite FTS5 inside the
        // folio itself. Every app used to add this identical line to survos_search.yaml; an app
        // can still override it there.
        if ($builder->hasExtension('survos_search')) {
            $builder->prependExtensionConfig('survos_search', ['adapters' => ['folio_fts' => 'sqlite-fts5://folio']]);
        }
        // Row pages call ux_icon() with vocabulary codes (term sets 'pla', 'per', …). data-bundle
        // registers the same aliases, but folio-bundle does not require it.
        if ($builder->hasExtension('ux_icons')) {
            $builder->prependExtensionConfig('ux_icons', ['aliases' => \Survos\DataContracts\Vocabulary\MuseumVocab::ICONS]);
        }
    }

}
