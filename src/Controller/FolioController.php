<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Doctrine\DBAL\Connection;
use Survos\DataContracts\Dto\Item\BaseItemDto;
use Survos\FolioBundle\Entity\{Core,Doc,Folio,Link,Page,Row,Term,TermSet};
use Survos\FolioBundle\Service\{FolioChatPromptSuggester,FolioChatService,FolioDtoTypeResolver,FolioService,FolioWordCloudService,RowClaimsResolver,RowTermsResolver};
use Survos\DataContracts\Vocabulary\RelationBinding;
use Survos\DataContracts\Vocabulary\TermSetBinding;
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;
use Survos\SearchBundle\Adapter\SqliteFts5\Fts5MatchQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment;

#[Route('/{folioCode}', requirements: ['folioCode' => self::FOLIO_CODE_PATTERN])]
final class FolioController extends AbstractController
{
    /**
     * A folioCode is either a bare public slug (one segment, e.g. "larco") or a real
     * "provider/dataset" code (exactly two segments, e.g. "mus/fpus") — never more than one
     * embedded "/". FolioRouteAttributeListener resolves the slug form before any controller
     * runs, so by the time an action executes {folioCode} is always the real code. A locale
     * suffix (".en") is allowed on either shape and is stripped by the same listener.
     *
     * Public so other folio-scoped controllers (e.g. FolioSearchController) share the exact
     * same requirement instead of re-declaring it.
     */
    public const string FOLIO_CODE_PATTERN = '[^/]+(?:/[^/]+)?';

    private const MAX_MAP_FEATURES = 2000;
    private const MAX_CLUSTER_ITEMS = 50;

    /** Zoom (Leaflet-style, 0-19) -> decimal places to round lat/lng to before grouping. Coarser at low zoom. */
    private const ZOOM_PRECISION = [
        0 => 0, 1 => 0, 2 => 0, 3 => 0,
        4 => 1, 5 => 1, 6 => 1,
        7 => 2, 8 => 2, 9 => 2,
        10 => 3, 11 => 3, 12 => 3,
    ];
    private const MAX_MAP_PRECISION = 4;

    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioDtoTypeResolver $dtoTypeResolver,
        private readonly FolioChatService $chat,
        private readonly FolioChatPromptSuggester $promptSuggester,
        private readonly FolioWordCloudService $wordCloud,
        private readonly Environment $twig,
        private readonly HttpClientInterface $httpClient,
        private readonly RowTermsResolver $rowTermsResolver,
        private readonly RowClaimsResolver $rowClaimsResolver,
        private readonly ?ImgproxyUrlBuilder $imgproxy = null,
    ) {}


    // The bare-slug form (survos-sites/scanseum#12) travels through the same {folioCode}
    // param as a real "provider/dataset" code — FolioRouteAttributeListener resolves it to
    // the real code (404ing on an unknown slug) before this action runs, and the generic
    // RouteIdentityValueResolver resolves $folio from the now-real {folioCode}.
    #[Route('', name: 'survos_folio_show')]
    public function show(Folio $folio): Response
    {
        $ctx = $this->folios->context($folio->code);
        $conn = $ctx->em->getConnection();
        $cores = $ctx->em->getRepository(Core::class)->findBy([], ['rowCount' => 'DESC', 'code' => 'ASC']);

        // Every core is first-class — no single "object" core is the centre. Each card gets its own
        // row count and DTO-type breakdown so the dashboard links straight into any core (obj, doc,
        // image, aut/people, …) rather than privileging objects.
        $coreSummaries = [];
        foreach ($cores as $core) {
            $coreSummaries[] = [
                'core' => $core,
                'dtoChoices' => $this->dtoChoices($conn->executeQuery(
                    'SELECT dto_type, COUNT(*) AS cnt FROM item WHERE core_id = ? GROUP BY dto_type ORDER BY cnt DESC',
                    [$core->id],
                )->fetchAllAssociative()),
            ];
        }

        $schemaTables = $conn->executeQuery(
            "SELECT name, kind, core_code, dto_type, label, row_count FROM schema_table ORDER BY kind, name"
        )->fetchAllAssociative();
        $views = $conn->executeQuery(
            "SELECT name FROM sqlite_master WHERE type = 'view' AND name LIKE 'dto_%' ORDER BY name"
        )->fetchFirstColumn();

        // The overview page's hero timeline: the primary (largest) core's year spread, if it has
        // one. Capped to a sample for the preview strip — the full unfiltered set is one click
        // away via "View all" (survos_folio_slideshow), which has no such cap. Counts/min/max
        // still reflect the true full range so the ruler itself isn't misleading.
        $timeline = null;
        if ($cores !== []) {
            $primaryCore = $cores[0];
            $timelineData = $this->yearTimelineData($conn, $primaryCore->id, limit: 300);
            if ($timelineData !== null) {
                $timeline = $timelineData + ['core' => $primaryCore];
            }
        }

        return $this->render('@SurvosFolioBundle/folio/show.html.twig', [
            'ctx'           => $ctx,
            'folio'         => $folio,
            'cores'         => $cores,
            'coreSummaries' => $coreSummaries,
            'totalRows'     => array_reduce($cores, static fn (int $sum, Core $core): int => $sum + $core->rowCount, 0),
            'docs'          => $ctx->em->getRepository(Doc::class)->findBy([], ['position' => 'ASC']),
            'schemaTables'  => $schemaTables,
            'views'         => $views,
            'linkTypes'     => $folio->linkTypes,
            'timeline'      => $timeline,
        ]);
    }

    /**
     * Shared by show() (a homepage-style preview, capped + always year-sorted) and slideshow()
     * (the full page, honouring the search/facet-derived sort). Returns null if the core has no
     * usable year spread (e.g. every row's year is null, or they're all the same year) — the
     * caller should just render without a timeline rather than show a degenerate one-point ruler.
     *
     * @return array{slides: list<array<string, mixed>>, slider: array<string, mixed>, counts: array<int, int>}|null
     */
    private function yearTimelineData(Connection $conn, string $coreId, int $limit = 0): ?array
    {
        $sort = $this->slideshowSortColumn('year:asc');
        \assert($sort !== null);

        $where = 'd.core_id = :core';
        $params = ['core' => $coreId];

        $ends = $conn->executeQuery(
            sprintf('SELECT MIN(%1$s) AS lo, MAX(%1$s) AS hi FROM item d WHERE %2$s', $sort['expr'], $where),
            $params,
        )->fetchAssociative();

        if ($ends === false || $ends['lo'] === null || $ends['hi'] === null || $ends['lo'] == $ends['hi']) {
            return null;
        }

        $counts = $conn->executeQuery(
            sprintf(
                'SELECT %1$s AS year, COUNT(*) AS n FROM item d WHERE %2$s AND %1$s IS NOT NULL GROUP BY %1$s',
                $sort['expr'],
                $where,
            ),
            $params,
        )->fetchAllKeyValue();

        $limitSql = $limit > 0 ? sprintf(' LIMIT %d', $limit) : '';
        $slides = $conn->executeQuery(
            sprintf(
                "SELECT d.local_id AS id,
                        coalesce(json_extract(d.dto_data, '\$.thumbnailUrl'), json_extract(d.dto_data, '\$.largeImageUrl')) AS thumb,
                        %s AS v
                 FROM item d WHERE %s ORDER BY %s ASC%s",
                $sort['expr'],
                $where,
                $sort['expr'],
                $limitSql,
            ),
            $params,
        )->fetchAllAssociative();

        return [
            'slides' => $slides,
            'slider' => [
                'numeric' => true,
                'min' => (int) $ends['lo'],
                'max' => (int) $ends['hi'],
                'value' => $slides === [] ? (int) $ends['lo'] : (int) $slides[0]['v'],
            ],
            'counts' => $counts,
        ];
    }

    #[Route('/download', name: 'survos_folio_download')]
    public function download(Folio $folio): BinaryFileResponse
    {
        $archivePath = $this->folios->archivePath($folio->code);
        if (!is_file($archivePath)) {
            throw $this->createNotFoundException(sprintf('Folio archive not found: %s', $folio->code));
        }

        $response = new BinaryFileResponse($archivePath);
        $response->headers->set('Content-Type', 'application/gzip');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('%s.folio.gz', str_replace('/', '_', $folio->code)),
        );

        return $response;
    }


    #[Route('/slideshow/{coreCode}/{filter}', name: 'survos_folio_slideshow_filtered', requirements: ['filter' => '[A-Za-z0-9_-]+'], options: ['expose' => true])]
    #[Route('/slideshow/{coreCode}', name: 'survos_folio_slideshow', options: ['expose' => true])]
    public function slideshow(Folio $folio, string $coreCode, Request $request, ?string $filter = null): Response
    {
        $ctx = $this->folios->context($folio->code);
        $core = $ctx->em->find(Core::class, Core::id($folio->code, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);

        $conn = $ctx->em->getConnection();
        $where = ['d.core_id = :core'];
        $params = ['core' => $core->id];

        // Continue straight from the user's refined grid: honour the same sort + facet filters the
        // ux-search component produces (sortBy=<prop>:<dir>, <facet>=v1~~v2, <facet>Min/<facet>Max, and
        // a pinned dtoType). The Slideshow button packs that whole query string into a single url-safe
        // base64 token carried as the {filter} path segment, which we decode back into a param map here.
        // Column/table rules mirror the SqliteFts5 adapter + App\Search\FolioRowSearch.
        $filterParams = $this->slideshowFilterParams($request, $filter);
        // A pinned dtoType now travels inside the token (applied as a facet); pull it out for display.
        $dtoType = isset($filterParams['dtoType']) && is_string($filterParams['dtoType']) && $filterParams['dtoType'] !== ''
            ? $filterParams['dtoType'] : null;
        $orderBy = $this->slideshowSortAndFilters($filterParams, $conn, $where, $params);
        $sortBy = is_string($filterParams['sortBy'] ?? null) ? $filterParams['sortBy'] : '';
        $sort = $this->slideshowSortColumn($sortBy);

        // A slideshow over a 60k-row core must not hydrate 60k Row entities (that OOMs). Materialise only
        // the lightweight {id, thumb} list the viewer needs (per-row detail is deferred). For a numeric
        // sort we also carry each slide's sort value `v`, so the slider can run over the real value range
        // (its position IS the year) instead of a 1…N index.
        $valueSelect = ($sort !== null && $sort['numeric']) ? sprintf(', %s AS v', $sort['expr']) : '';
        $slides = $conn->executeQuery(
            sprintf(
                "SELECT d.local_id AS id,
                        coalesce(json_extract(d.dto_data, '\$.thumbnailUrl'), json_extract(d.dto_data, '\$.largeImageUrl')) AS thumb%s
                 FROM item d WHERE %s %s",
                $valueSelect,
                implode(' AND ', $where),
                $orderBy,
            ),
            $params,
        )->fetchAllAssociative();

        // The slider model. Default: navigate by slide index (1…N). For a numeric sort with a real
        // spread, switch to value mode: min/max are the field's actual min/max over the same filtered
        // set, so the slider reads e.g. 1908 … 2020 and its handle tracks the year.
        $slider = ['numeric' => false, 'min' => 1, 'max' => count($slides), 'value' => 1];
        // year -> count, for the slider's badge (Fortepan.hu's "little black number that hovers
        // above the red timeline marker" -- fortepan.us's own slider has no equivalent, so this is
        // net-new rather than ported). Only meaningful in numeric (year) mode.
        $counts = [];
        if ($sort !== null && $sort['numeric'] && $slides !== []) {
            $ends = $conn->executeQuery(
                sprintf('SELECT MIN(%1$s) AS lo, MAX(%1$s) AS hi FROM item d WHERE %2$s', $sort['expr'], implode(' AND ', $where)),
                $params,
            )->fetchAssociative();
            if ($ends !== false && $ends['lo'] !== null && $ends['hi'] !== null && $ends['lo'] != $ends['hi']) {
                $slider = [
                    'numeric' => true,
                    'min' => (int) $ends['lo'],
                    'max' => (int) $ends['hi'],
                    'value' => (int) $slides[0]['v'],
                ];

                $counts = $conn->executeQuery(
                    sprintf(
                        'SELECT %1$s AS year, COUNT(*) AS n FROM item d WHERE %2$s AND %1$s IS NOT NULL GROUP BY %1$s',
                        $sort['expr'],
                        implode(' AND ', $where),
                    ),
                    $params,
                )->fetchAllKeyValue();
            }
        }

        return $this->render('@SurvosFolioBundle/folio/slideshow.html.twig', [
            'ctx' => $ctx,
            'folio' => $folio,
            'core' => $core,
            'dtoType' => $dtoType,
            'slides' => $slides,
            'slider' => $slider,
            'counts' => $counts,
        ]);
    }

    // Explicit priority: $filter's default (?string $filter = null) makes Symfony infer it as an
    // OPTIONAL trailing segment on survos_folio_map_filtered too, so its compiled regex matches
    // bare /{folioCode}/map URLs just as well as ...+/{filter} ones. With equal (default)
    // priority, declaration order decides ties — survos_folio_map would never actually match
    // anything, shadowed entirely by its own "_filtered" sibling (and TenantMenu's "Map" link
    // would never show as active, since the resolved route name never equals what it links to).
    #[Route('/map/{filter}', name: 'survos_folio_map_filtered', requirements: ['filter' => '[A-Za-z0-9_-]+'], options: ['expose' => true])]
    #[Route('/map', name: 'survos_folio_map', options: ['expose' => true], priority: 10)]
    public function map(Folio $folio, ?string $filter = null): Response
    {
        $em = $this->folios->context($folio->code)->em;
        // Same "obj first, else largest core" default the tenant/gallery pages already use --
        // city-level clusters never separate by zooming further (see map_controller.js), so their
        // popup instead offers a "View all in <city>" link into this core's slideshow/grid.
        $defaultCore = $em->find(Core::class, Core::id($folio->code, 'obj'))
            ?? ($em->getRepository(Core::class)->findBy([], ['rowCount' => 'DESC'])[0] ?? null);

        return $this->render('@SurvosFolioBundle/folio/map.html.twig', [
            'folio' => $folio,
            'filter' => $filter,
            'hasUxMap' => class_exists(\Symfony\UX\Map\Map::class),
            // Same placeholder-token convention as PhotoGrid's rowUrlTemplate: defaults to the
            // chrome-free generic detail page (redirects to survos_folio_row_show), but a host app
            // can override map.html.twig to pass its own branded photo-page template instead.
            'rowUrlTemplate' => $this->generateUrl('survos_folio_row_shortcut', [
                'folioCode' => $folio->code,
                'localId' => '__LOCAL_ID__',
            ]),
            'slideshowBaseUrl' => $defaultCore === null ? null : $this->generateUrl('survos_folio_slideshow', [
                'folioCode' => $folio->code,
                'coreCode' => $defaultCore->code,
            ]),
        ]);
    }

    /**
     * Masonry photo browser (twig:folio:photo-grid). {coreCode} is optional — omit it for a
     * single one-click "Gallery" link (folio-level menus) that falls back to the same "obj
     * first, else largest core" default map() uses; pass it explicitly from a per-core context
     * (e.g. show.html.twig's core cards, alongside Grid/List/Slideshow) to browse that core
     * specifically. Leaves rowUrlTemplate at PhotoGrid's own default (survos_folio_row_show)
     * rather than overriding it the way openfoto's tenant/gallery.html.twig does for its own
     * chrome-preserving modal — this is the plain full-page browse-then-click-through view.
     *
     * priority: 10 on the shorter route for the same reason map() has it (see that method's
     * comment): an optional {coreCode} with no distinguishing requirement makes the compiler
     * collapse both routes into one, keeping only _core's name for matching — /gallery would
     * resolve as survos_folio_gallery_core with a null coreCode instead of survos_folio_gallery.
     */
    #[Route('/gallery/{coreCode}', name: 'survos_folio_gallery_core', options: ['expose' => true])]
    #[Route('/gallery', name: 'survos_folio_gallery', options: ['expose' => true], priority: 10)]
    public function gallery(Folio $folio, ?string $coreCode = null): Response
    {
        $em = $this->folios->context($folio->code)->em;

        $core = $coreCode !== null
            ? ($em->find(Core::class, Core::id($folio->code, $coreCode)) ?? throw $this->createNotFoundException($coreCode))
            : ($em->find(Core::class, Core::id($folio->code, 'obj'))
                ?? ($em->getRepository(Core::class)->findBy([], ['rowCount' => 'DESC'])[0] ?? null)
                ?? throw $this->createNotFoundException('No core to browse.'));

        return $this->render('@SurvosFolioBundle/folio/gallery.html.twig', [
            'folio' => $folio,
            'coreCode' => $core->code,
        ]);
    }

    /**
     * GeoJSON marker/cluster feed backing the map view. SQLite has no spatial extension here (no
     * mod_spatialite, no R-Tree), and doesn't need one: a bounding box is a plain BETWEEN, and
     * "cluster nearby points" only needs a coarse grid, not real GIS. We round lat/lng to `precision`
     * decimal places (derived from the map's zoom level, coarser at low zoom) and GROUP BY the
     * rounded pair — the same idea as Mapbox/Supercluster's cluster/point_count convention or
     * Elasticsearch's geotile_grid, just done with SQL rounding instead of a spatial index. A bucket
     * with exactly one item is returned as a real point (with its own properties), not a size-1
     * cluster. Honours the same filter-token mechanism as the Slideshow link (see
     * slideshowFilterParams()/slideshowSortAndFilters()), so the map can open pre-filtered to a
     * search's result set.
     *
     * zoom is a required, always-LAST path segment (never a query param) so it can't collide with the
     * filter-token's own reserved-params handling, and so the JS side can build a fetch URL by simply
     * appending `/{zoom}` to a server-computed base (see map_controller.js) rather than juggling query
     * strings alongside a base64 path segment.
     */
    #[Route('/map.geojson/{filter}/{zoom}', name: 'survos_folio_map_geojson_filtered', requirements: ['filter' => '[A-Za-z0-9_-]+', 'zoom' => '\d+'], options: ['expose' => true])]
    #[Route('/map.geojson/{zoom}', name: 'survos_folio_map_geojson', requirements: ['zoom' => '\d+'], options: ['expose' => true])]
    public function mapGeoJson(Folio $folio, int $zoom, Request $request, ?string $filter = null): JsonResponse
    {
        $conn = $this->folios->context($folio->code)->em->getConnection();
        [$where, $params] = $this->mapWhereAndParams($conn, $request, $filter);

        $precision = self::ZOOM_PRECISION[$zoom] ?? self::MAX_MAP_PRECISION;

        $rows = $conn->executeQuery(
            sprintf(
                "SELECT
                    ROUND(CAST(json_extract(d.dto_data, '\$.latitude') AS REAL), :precision) AS bucketLat,
                    ROUND(CAST(json_extract(d.dto_data, '\$.longitude') AS REAL), :precision) AS bucketLng,
                    COUNT(*) AS n,
                    AVG(CAST(json_extract(d.dto_data, '\$.latitude') AS REAL)) AS lat,
                    AVG(CAST(json_extract(d.dto_data, '\$.longitude') AS REAL)) AS lng,
                    MIN(d.local_id) AS id,
                    MIN(d.label) AS label,
                    MIN(json_extract(d.dto_data, '\$.city')) AS city,
                    COUNT(DISTINCT json_extract(d.dto_data, '\$.city')) AS cityCount,
                    MIN(coalesce(json_extract(d.dto_data, '\$.thumbnailUrl'), json_extract(d.dto_data, '\$.largeImageUrl'))) AS thumbnailUrl,
                    MIN(json_extract(d.dto_data, '\$.year')) AS year
                 FROM item d WHERE %s
                 GROUP BY bucketLat, bucketLng
                 LIMIT :limit",
                implode(' AND ', $where),
            ),
            $params + ['precision' => $precision, 'limit' => self::MAX_MAP_FEATURES],
        )->fetchAllAssociative();

        return new JsonResponse([
            'type' => 'FeatureCollection',
            'features' => array_map(fn (array $row): array => [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [(float) $row['lng'], (float) $row['lat']]],
                'properties' => (int) $row['n'] > 1
                    // bucketLat/bucketLng let the client ask mapClusterItems() for exactly this
                    // bucket's rows (a mini-slideshow on click) -- the averaged lat/lng above isn't
                    // reproducible from the client side, but the rounded bucket key is. city is only
                    // included when every row in the bucket shares it (cityCount === 1) -- a "View all
                    // in <city>" link is only honest when the whole cluster really is that one place,
                    // not a coincidental rounding overlap of otherwise-unrelated points.
                    ? ['cluster' => true, 'point_count' => (int) $row['n'], 'bucketLat' => (float) $row['bucketLat'], 'bucketLng' => (float) $row['bucketLng'], 'city' => (int) $row['cityCount'] === 1 ? $row['city'] : null]
                    : ['cluster' => false, 'id' => $row['id'], 'label' => $row['label'], 'city' => $row['city'], 'thumbnailUrl' => $this->mapThumbnailUrl($row['thumbnailUrl']), 'year' => $row['year']],
            ], $rows),
        ]);
    }

    /**
     * The individual items behind one cluster bucket (same bucketLat/bucketLng/zoom a mapGeoJson()
     * cluster feature carries), for the mini-slideshow popup on cluster click -- plain JSON list, not
     * GeoJSON, since these aren't independently plottable (they share one map position by definition).
     */
    #[Route('/map.geojson/{filter}/{zoom}/at/{bucketLat}/{bucketLng}', name: 'survos_folio_map_cluster_filtered', requirements: ['filter' => '[A-Za-z0-9_-]+', 'zoom' => '\d+', 'bucketLat' => '-?\d+(\.\d+)?', 'bucketLng' => '-?\d+(\.\d+)?'], options: ['expose' => true])]
    #[Route('/map.geojson/{zoom}/at/{bucketLat}/{bucketLng}', name: 'survos_folio_map_cluster', requirements: ['zoom' => '\d+', 'bucketLat' => '-?\d+(\.\d+)?', 'bucketLng' => '-?\d+(\.\d+)?'], options: ['expose' => true])]
    public function mapClusterItems(Folio $folio, int $zoom, string $bucketLat, string $bucketLng, Request $request, ?string $filter = null): JsonResponse
    {
        $conn = $this->folios->context($folio->code)->em->getConnection();
        [$where, $params] = $this->mapWhereAndParams($conn, $request, $filter);

        $precision = self::ZOOM_PRECISION[$zoom] ?? self::MAX_MAP_PRECISION;
        // CAST(:param AS REAL) matters here: PDO binds a PHP float as TEXT by default, and SQLite's
        // storage-class comparison rules mean a numeric value (what ROUND() returns) never equals a
        // TEXT value regardless of content -- confirmed 0 rows without the cast, correct with it.
        $where[] = "ROUND(CAST(json_extract(d.dto_data, '$.latitude') AS REAL), :precision) = CAST(:bucketLat AS REAL)";
        $where[] = "ROUND(CAST(json_extract(d.dto_data, '$.longitude') AS REAL), :precision) = CAST(:bucketLng AS REAL)";
        $params += ['precision' => $precision, 'bucketLat' => (float) $bucketLat, 'bucketLng' => (float) $bucketLng];

        $rows = $conn->executeQuery(
            sprintf(
                "SELECT
                    d.local_id AS id,
                    d.label,
                    json_extract(d.dto_data, '\$.city') AS city,
                    coalesce(json_extract(d.dto_data, '\$.thumbnailUrl'), json_extract(d.dto_data, '\$.largeImageUrl')) AS thumbnailUrl,
                    json_extract(d.dto_data, '\$.year') AS year
                 FROM item d WHERE %s
                 LIMIT :limit",
                implode(' AND ', $where),
            ),
            $params + ['limit' => self::MAX_CLUSTER_ITEMS],
        )->fetchAllAssociative();

        return new JsonResponse(array_map(fn (array $row): array => [
            'id' => $row['id'],
            'label' => $row['label'],
            'city' => $row['city'],
            'thumbnailUrl' => $this->mapThumbnailUrl($row['thumbnailUrl']),
            'year' => $row['year'],
        ], $rows));
    }

    /**
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function mapWhereAndParams(Connection $conn, Request $request, ?string $filter): array
    {
        $where = [
            "json_extract(d.dto_data, '$.latitude') IS NOT NULL",
            "json_extract(d.dto_data, '$.longitude') IS NOT NULL",
        ];
        $params = [];
        $this->slideshowSortAndFilters($this->slideshowFilterParams($request, $filter), $conn, $where, $params);

        $bbox = $this->parseBbox($request->query->getString('bbox', ''));
        if ($bbox !== null) {
            $where[] = "CAST(json_extract(d.dto_data, '$.latitude') AS REAL) BETWEEN :bboxS AND :bboxN";
            $where[] = "CAST(json_extract(d.dto_data, '$.longitude') AS REAL) BETWEEN :bboxW AND :bboxE";
            $params += ['bboxW' => $bbox[0], 'bboxS' => $bbox[1], 'bboxE' => $bbox[2], 'bboxN' => $bbox[3]];
        }

        return [$where, $params];
    }

    /**
     * Same imgproxy 'thumb' preset the search grid uses (folio_row.html.twig's `|imgproxy('thumb')`),
     * applied server-side since map popups are built in JS from this JSON, not rendered in Twig.
     *
     * @todo this precomputes every row's thumb server-side; @tacman1123/twig-browser (already a
     *       dependency of meili-bundle/js-twig-bundle/api-grid-bundle) can run the real `imgproxy`
     *       Twig filter client-side, which would let map_controller.js call the SAME filter used
     *       everywhere else instead of duplicating the transform here.
     */
    private function mapThumbnailUrl(?string $url): ?string
    {
        if ($url === null || $this->imgproxy === null) {
            return $url;
        }

        return $this->imgproxy->resizePreset($url, 'thumb');
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null west,south,east,north
     */
    private function parseBbox(string $bbox): ?array
    {
        if ($bbox === '') {
            return null;
        }
        $parts = array_map('trim', explode(',', $bbox));
        if (count($parts) !== 4 || !array_reduce($parts, static fn (bool $ok, string $p): bool => $ok && is_numeric($p), true)) {
            return null;
        }

        return array_map('floatval', $parts);
    }

    /**
     * The grid state the slideshow filters by, as a param map. The Slideshow button packs the grid's
     * query string into a url-safe base64 token (lossless), carried as the {filter} path segment (or the
     * legacy `?f=` query param); decode it back to a map. Falls back to the raw query params so
     * hand-written `?sortBy=…&cul=…` links keep working.
     *
     * @return array<string, mixed>
     */
    private function slideshowFilterParams(Request $request, ?string $filter = null): array
    {
        $token = ($filter !== null && $filter !== '') ? $filter : $request->query->getString('f');
        if ($token !== '') {
            $decoded = base64_decode(strtr($token, '-_', '+/'), true);
            if ($decoded !== false && $decoded !== '') {
                parse_str($decoded, $parsed);

                return $parsed;
            }
        }

        return $request->query->all();
    }

    /**
     * Translate the grid's param map into SQL against the folio `item d` table, mutating $where/$params
     * and returning the ORDER BY clause. Full-text (`query`) becomes an item_fts MATCH; term facets use
     * the denormalised item_facet table (matching the SqliteFts5 adapter, so multi-valued facets behave
     * identically); ranges and sorts read json_extract(d.dto_data, …). `core` is pinned by the route path.
     * Property→column rules mirror App\Search\FolioRowSearch.
     *
     * @param array<string, mixed> $source
     * @param list<string>         $where
     * @param array<string, mixed> $params
     */
    private function slideshowSortAndFilters(array $source, Connection $conn, array &$where, array &$params): string
    {
        $hasFacetTable = (bool) $conn->executeQuery(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'item_facet'",
        )->fetchOne();

        // Full-text: replay the grid's `query` as an FTS MATCH so the slideshow plays the same
        // text-filtered set. Build the match with the very same helper the SqliteFts5 search adapter
        // uses, so operators/phrases/grouping behave identically to the grid.
        $query = $source['query'] ?? '';
        if (is_string($query) && ($match = Fts5MatchQuery::build($query)) !== ''
            && $conn->executeQuery("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'item_fts'")->fetchOne()
        ) {
            $where[] = 'd.rowid IN (SELECT rowid FROM item_fts WHERE item_fts MATCH :ftsQuery)';
            $params['ftsQuery'] = $match;
        }

        // `core` is always pinned by the slideshow route path, so drop it; `dtoType` is NOT always in
        // the path (the grid exposes it as a facet on core-only pages), so let it flow through as a
        // normal item_facet filter. query/page/sortBy/placeholder are handled out of band, not as facets.
        // bbox is the map view's own state (see mapGeoJson()), reserved here too since this method is
        // shared -- without it, a `?bbox=...` request with no filter token present falls through
        // slideshowFilterParams()'s raw-query-params fallback and gets read as a facet filter on a
        // nonexistent `bbox` property, filtering out every row. (zoom used to have the same problem;
        // it's now a required route segment instead of a query param, so it can't collide here at all.)
        $reserved = ['query', 'page', 'sortBy', 'core', 'ux_search_placeholder', 'hitsPerPage', 'bbox'];
        $i = 0;
        foreach ($source as $key => $value) {
            if (!is_string($value) || $value === '' || in_array($key, $reserved, true)) {
                continue;
            }

            $isRange = str_ends_with($key, 'Min') || str_ends_with($key, 'Max');
            $property = $isRange ? substr($key, 0, -3) : $key;
            // `core` is pinned by the path; reject anything that isn't a plain property name.
            if ($property === 'core' || !preg_match('/^[A-Za-z0-9_:]+$/', $property)) {
                continue;
            }
            $column = sprintf("json_extract(d.dto_data, '$.%s')", $property);

            if ($isRange) {
                if (!is_numeric($value)) {
                    continue;
                }
                // CAST both sides to REAL: json_extract values store as int/real while URL bounds are
                // strings, and SQLite orders int < text across storage classes (mirrors the adapter).
                $name = 'r' . $i++;
                $where[] = sprintf('CAST(%s AS REAL) %s CAST(:%s AS REAL)', $column, str_ends_with($key, 'Min') ? '>=' : '<=', $name);
                $params[$name] = $value;
                continue;
            }

            $values = array_values(array_filter(
                explode('~~', $value),
                static fn (string $v): bool => trim($v) !== '',
            ));
            if ($values === []) {
                continue;
            }

            $names = [];
            foreach (array_slice($values, 0, 100) as $v) {
                $name = 't' . $i++;
                $names[] = ':' . $name;
                $params[$name] = $v;
            }

            if ($hasFacetTable) {
                $field = 'field' . $i++;
                $params[$field] = $property;
                $where[] = sprintf(
                    'EXISTS (SELECT 1 FROM item_facet fv WHERE fv.item_rowid = d.rowid AND fv.field = :%s AND fv.value IN (%s))',
                    $field,
                    implode(', ', $names),
                );
            } else {
                $where[] = sprintf('%s IN (%s)', $column, implode(', ', $names));
            }
        }

        $sortBy = $source['sortBy'] ?? '';

        return $this->slideshowOrderBy(is_string($sortBy) ? $sortBy : '');
    }

    private function slideshowOrderBy(string $sortBy): string
    {
        $sort = $this->slideshowSortColumn($sortBy);

        return $sort !== null ? sprintf('ORDER BY %s %s', $sort['expr'], $sort['dir']) : 'ORDER BY d.rowid';
    }

    /**
     * The active sort as a column expression + direction (+ whether it's numeric), or null for the
     * default insertion order. Mirrors App\Search\FolioRowSearch sort columns. The `numeric` flag drives
     * the value-based slider: a numeric sort (Year) makes the slider run over the real value range so its
     * position IS the year, not a 1…N index.
     *
     * @return array{expr: string, dir: 'ASC'|'DESC', numeric: bool}|null
     */
    private function slideshowSortColumn(string $sortBy): ?array
    {
        $columns = [
            'label' => ['expr' => 'd.label', 'numeric' => false],
            'year' => ['expr' => "json_extract(d.dto_data, '$.year')", 'numeric' => true],
        ];

        if ($sortBy === '' || !str_contains($sortBy, ':')) {
            return null;
        }
        [$property, $direction] = explode(':', $sortBy, 2);
        if (!isset($columns[$property])) {
            return null;
        }

        return [
            'expr' => $columns[$property]['expr'],
            'dir' => strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC',
            'numeric' => $columns[$property]['numeric'],
        ];
    }

    #[Route('/schema', name: 'survos_folio_schema')]
    public function schema(Folio $folio): Response
    {
        $ctx = $this->folios->context($folio->code);
        $conn = $ctx->em->getConnection();
        $sm = $conn->createSchemaManager();

        $tables = [];
        foreach ($sm->listTableNames() as $tableName) {
            try {
                $table = $sm->introspectTable($tableName);
            } catch (\Throwable) {
                continue;
            }

            $indexes = [];
            foreach ($table->getIndexes() as $idx) {
                if ($idx->isPrimary()) {
                    continue;
                }
                $indexes[] = [
                    'name' => $idx->getName(),
                    'columns' => implode(', ', $idx->getColumns()),
                    'unique' => $idx->isUnique() ? 'yes' : '',
                ];
            }

            $tables[] = [
                'name' => $table->getName(),
                'indexes' => $indexes,
            ];
        }

        $ddl = [];
        $nativeConn = $conn->getNativeConnection();
        \assert($nativeConn instanceof \PDO);
        foreach ($nativeConn->query("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $ddl[(string) $row['name']] = $this->formatDdl((string) $row['sql']);
        }

        $views = [];
        foreach ($nativeConn->query("SELECT name, sql FROM sqlite_master WHERE type = 'view' ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $views[] = ['name' => (string) $row['name'], 'sql' => (string) $row['sql']];
        }

        return $this->render('@SurvosFolioBundle/folio/schema.html.twig', [
            'ctx' => $ctx,
            'folio' => $folio,
            'tables' => $tables,
            'ddl' => $ddl,
            'views' => $views,
        ]);
    }

    #[Route('/chat', name: 'survos_folio_chat')]
    public function chat(Folio $folio, Request $request): Response
    {
        $ctx = $this->folios->context($folio->code);
        $question = trim($request->request->getString('q', $request->query->getString('q')));
        $coreCode = $request->request->getString('core', $request->query->getString('core')) ?: null;
        $dtoType = $request->request->getString('dtoType', $request->query->getString('dtoType')) ?: null;
        $limit = max(1, min(50, $request->request->getInt('limit', $request->query->getInt('limit', 24))));
        $conversationId = trim($request->request->getString('conversation', $request->query->getString('conversation')));
        if ($conversationId === '') {
            $conversationId = bin2hex(random_bytes(16));
        }
        $result = null;
        $error = null;

        if ($question !== '') {
            try {
                $result = $this->chat->ask($ctx, $question, $coreCode, $dtoType, $limit, $conversationId);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        $cores = $ctx->em->getRepository(Core::class)->findBy([], ['code' => 'ASC']);
        $dtoChoices = [];
        foreach ($cores as $core) {
            $dtoChoices[$core->code] = $this->dtoChoices($ctx->em->getConnection()->executeQuery(
                'SELECT dto_type, COUNT(*) AS cnt FROM item WHERE core_id = ? GROUP BY dto_type ORDER BY cnt DESC',
                [$core->id],
            )->fetchAllAssociative());
        }

        return $this->render('@SurvosFolioBundle/folio/chat.html.twig', [
            'ctx' => $ctx,
            'folio' => $folio,
            'cores' => $cores,
            'dtoChoices' => $dtoChoices,
            'question' => $question,
            'selectedCore' => $coreCode,
            'selectedDtoType' => $dtoType,
            'limit' => $limit,
            'conversationId' => $conversationId,
            'promptSuggestions' => $this->promptSuggester->suggest($ctx, $dtoChoices),
            'wordCloud' => $this->wordCloud->cloud($ctx, 32),
            'result' => $result,
            'error' => $error,
        ]);
    }

    #[Route('/row/{localId}', name: 'survos_folio_row_shortcut')]
    public function rowShortcut(Folio $folio, string $localId): Response
    {
        $ctx = $this->folios->context($folio->code);
        $rows = $ctx->em->getRepository(Row::class)
            ->createQueryBuilder('r')
            ->join('r.core', 'c')
            ->where('c.id LIKE :folioPrefix')
            ->andWhere('r.localId = :localId')
            ->setParameter('folioPrefix', "$folio->code:%")
            ->setParameter('localId', $localId)
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();

        $row = $rows[0] ?? throw $this->createNotFoundException($localId);
        \assert($row instanceof Row);

        return $this->redirectToRoute('survos_folio_row_show', [
            'folioCode' => $folio->code,
            'coreCode' => $row->getCoreCode(),
            'dtoType' => $row->dtoType ?: 'row',
            'localId' => $row->localId,
        ]);
    }


    /**
     * "Results of an AI task" — the claim_run (prompt/model/tokens/response) plus the claims it
     * produced, served from the folio's local `claim_run` table (ingested from the shared store by
     * claims:fetch → folio build). Linked from each claim's runId on the detail page.
     */
    #[Route('/run/{runId}', name: 'survos_folio_run', options: ['expose' => true])]
    public function run(Folio $folio, string $runId): Response
    {
        $conn = $this->folios->context($folio->code)->em->getConnection();

        try {
            $run = $conn->fetchAssociative('SELECT * FROM claim_run WHERE id = :id', ['id' => $runId]);
        } catch (\Throwable) {
            $run = false; // no claim_run table in this folio (rebuild after claims:fetch to populate)
        }
        if (!$run) {
            throw $this->createNotFoundException(sprintf('No claim run %s in %s', $runId, $folio->code));
        }

        $claims = $conn->fetchAllAssociative(
            'SELECT predicate, value, source, confidence, item_id FROM claim WHERE run_id = :id ORDER BY predicate',
            ['id' => $runId],
        );

        return $this->render('@SurvosFolioBundle/folio/run.html.twig', [
            'folio' => $folio,
            'folioCode' => $folio->code,
            'run' => $run,
            'claims' => $claims,
        ]);
    }

    #[Route('/{coreCode}/{dtoType}/{localId}/chat', name: 'survos_folio_item_chat', options: ['expose' => true])]
    public function itemChat(Request $request, Folio $folio, string $coreCode, string $dtoType, string $localId): Response
    {
        $ctx = $this->folios->context($folio->code);
        $core = $ctx->em->find(Core::class, Core::id($folio->code, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);
        $row = $ctx->em->find(Row::class, Row::id($core->id, $localId))
            ?? throw $this->createNotFoundException($localId);

        if ($row->dtoType && $dtoType !== $row->dtoType) {
            return $this->redirectToRoute('survos_folio_item_chat', [
                'folioCode' => $folio->code,
                'coreCode' => $coreCode,
                'dtoType' => $row->dtoType,
                'localId' => $localId,
            ]);
        }

        $pages = array_values($row->pages->toArray());
        $question = trim($request->request->getString('q', $request->query->getString('q')));
        $conversationId = trim($request->request->getString('conversation', $request->query->getString('conversation')));
        if ($conversationId === '') {
            $conversationId = bin2hex(random_bytes(16));
        }
        $chatScope = sprintf('item:%s:%s:%s', $ctx->folioCode, $core->code, $row->localId);
        $chatHistory = $this->chatHistory($request, $chatScope, $conversationId);
        $contextSections = $this->itemScholarContext($core, $row, $pages);
        $chatContextSections = $this->contextWithChatHistory($contextSections, $chatHistory);
        $result = $question !== ''
            ? $this->chat->askScoped($ctx, $question, 'Document: ' . ($row->label ?: $row->localId), $chatContextSections, $conversationId)
            : null;
        if ($result !== null) {
            $chatHistory = $this->appendChatTurn($request, $chatScope, $conversationId, $result);
        }

        return $this->render('@SurvosFolioBundle/folio/item_chat.html.twig', [
            'ctx' => $ctx,
            'folio' => $folio,
            'core' => $core,
            'row' => $row,
            'pages' => $pages,
            'question' => $question,
            'conversationId' => $conversationId,
            'contextSections' => $contextSections,
            'chatHistory' => $chatHistory,
            'result' => $result,
        ]);
    }

    #[Route('/{coreCode}/{dtoType}/{localId}/page/{seq}/chat', name: 'survos_folio_page_chat', requirements: ['seq' => '\d+'], options: ['expose' => true])]
    public function pageChat(Request $request, Folio $folio, string $coreCode, string $dtoType, string $localId, int $seq): Response
    {
        $ctx = $this->folios->context($folio->code);
        $core = $ctx->em->find(Core::class, Core::id($folio->code, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);
        $row = $ctx->em->find(Row::class, Row::id($core->id, $localId))
            ?? throw $this->createNotFoundException($localId);

        if ($row->dtoType && $dtoType !== $row->dtoType) {
            return $this->redirectToRoute('survos_folio_page_chat', [
                'folioCode' => $folio->code,
                'coreCode' => $coreCode,
                'dtoType' => $row->dtoType,
                'localId' => $localId,
                'seq' => $seq,
            ]);
        }

        $pages = array_values($row->pages->toArray());
        $page = null;
        foreach ($pages as $candidate) {
            if ($candidate->seq === $seq) {
                $page = $candidate;
                break;
            }
        }

        if ($page === null) {
            throw $this->createNotFoundException(sprintf('Page %d was not found for %s.', $seq, $localId));
        }

        $question = trim($request->request->getString('q', $request->query->getString('q')));
        $conversationId = trim($request->request->getString('conversation', $request->query->getString('conversation')));
        if ($conversationId === '') {
            $conversationId = bin2hex(random_bytes(16));
        }
        $chatScope = sprintf('page:%s:%s:%s:%d', $ctx->folioCode, $core->code, $row->localId, $page->seq);
        $chatHistory = $this->chatHistory($request, $chatScope, $conversationId);
        $contextSections = $this->pageScholarContext($core, $row, $page);
        $chatContextSections = $this->contextWithChatHistory($contextSections, $chatHistory);
        $result = $question !== ''
            ? $this->chat->askScoped($ctx, $question, sprintf('Page %d of document %s', $page->seq, $row->label ?: $row->localId), $chatContextSections, $conversationId)
            : null;
        if ($result !== null) {
            $chatHistory = $this->appendChatTurn($request, $chatScope, $conversationId, $result);
        }

        return $this->render('@SurvosFolioBundle/folio/page_chat.html.twig', [
            'ctx' => $ctx,
            'folio' => $folio,
            'core' => $core,
            'row' => $row,
            'page' => $page,
            'pages' => $pages,
            'question' => $question,
            'conversationId' => $conversationId,
            'contextSections' => $contextSections,
            'chatHistory' => $chatHistory,
            'result' => $result,
        ]);
    }

    /**
     * Server-side fetch of a page's source file, returned inline as text. Some sources (e.g. NARA's
     * plain-text digital objects) serve their file with `Content-Disposition: attachment`, which
     * forces a download on direct/iframe navigation to the origin URL — proxying through here (like
     * imgproxy already does for images) lets us control the response headers instead.
     */
    #[Route('/{coreCode}/{dtoType}/{localId}/page/{seq}/raw', name: 'survos_folio_page_raw', requirements: ['seq' => '\d+'], options: ['expose' => true])]
    public function pageRaw(Folio $folio, string $coreCode, string $dtoType, string $localId, int $seq): Response
    {
        $ctx = $this->folios->context($folio->code);
        $core = $ctx->em->find(Core::class, Core::id($folio->code, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);
        $row = $ctx->em->find(Row::class, Row::id($core->id, $localId))
            ?? throw $this->createNotFoundException($localId);

        $page = null;
        foreach ($row->pages as $candidate) {
            if ($candidate->seq === $seq) {
                $page = $candidate;
                break;
            }
        }
        if ($page === null) {
            throw $this->createNotFoundException(sprintf('Page %d was not found for %s.', $seq, $localId));
        }

        $upstream = $this->httpClient->request('GET', $page->url);

        return new Response($upstream->getContent(), $upstream->getStatusCode(), [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    #[Route('/{coreCode}/{dtoType}/{localId}/chat/stream', name: 'survos_folio_item_chat_stream', methods: ['POST'], options: ['expose' => true])]
    public function itemChatStream(Request $request, Folio $folio, string $coreCode, string $dtoType, string $localId): StreamedResponse
    {
        $ctx = $this->folios->context($folio->code);
        $core = $ctx->em->find(Core::class, Core::id($folio->code, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);
        $row = $ctx->em->find(Row::class, Row::id($core->id, $localId))
            ?? throw $this->createNotFoundException($localId);

        if ($row->dtoType && $dtoType !== $row->dtoType) {
            throw $this->createNotFoundException(sprintf('DTO type "%s" does not match "%s".', $dtoType, $row->dtoType));
        }

        $pages = array_values($row->pages->toArray());
        $question = trim($request->request->getString('q'));
        $conversationId = trim($request->request->getString('conversation'));
        if ($conversationId === '') {
            $conversationId = bin2hex(random_bytes(16));
        }

        $chatScope = sprintf('item:%s:%s:%s', $ctx->folioCode, $core->code, $row->localId);
        $chatHistory = $this->chatHistory($request, $chatScope, $conversationId);
        $contextSections = $this->contextWithChatHistory($this->itemScholarContext($core, $row, $pages), $chatHistory);

        return $this->streamScholarResponse(
            $request,
            $ctx,
            $chatScope,
            $conversationId,
            $question,
            'Document: ' . ($row->label ?: $row->localId),
            $contextSections,
        );
    }

    #[Route('/{coreCode}/{dtoType}/{localId}/page/{seq}/chat/stream', name: 'survos_folio_page_chat_stream', requirements: ['seq' => '\d+'], methods: ['POST'], options: ['expose' => true])]
    public function pageChatStream(Request $request, Folio $folio, string $coreCode, string $dtoType, string $localId, int $seq): StreamedResponse
    {
        $ctx = $this->folios->context($folio->code);
        $core = $ctx->em->find(Core::class, Core::id($folio->code, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);
        $row = $ctx->em->find(Row::class, Row::id($core->id, $localId))
            ?? throw $this->createNotFoundException($localId);

        if ($row->dtoType && $dtoType !== $row->dtoType) {
            throw $this->createNotFoundException(sprintf('DTO type "%s" does not match "%s".', $dtoType, $row->dtoType));
        }

        $pages = array_values($row->pages->toArray());
        $page = null;
        foreach ($pages as $candidate) {
            if ($candidate->seq === $seq) {
                $page = $candidate;
                break;
            }
        }

        if ($page === null) {
            throw $this->createNotFoundException(sprintf('Page %d was not found for %s.', $seq, $localId));
        }

        $question = trim($request->request->getString('q'));
        $conversationId = trim($request->request->getString('conversation'));
        if ($conversationId === '') {
            $conversationId = bin2hex(random_bytes(16));
        }

        $chatScope = sprintf('page:%s:%s:%s:%d', $ctx->folioCode, $core->code, $row->localId, $page->seq);
        $chatHistory = $this->chatHistory($request, $chatScope, $conversationId);
        $contextSections = $this->contextWithChatHistory($this->pageScholarContext($core, $row, $page), $chatHistory);

        return $this->streamScholarResponse(
            $request,
            $ctx,
            $chatScope,
            $conversationId,
            $question,
            sprintf('Page %d of document %s', $page->seq, $row->label ?: $row->localId),
            $contextSections,
        );
    }

    /**
     * @param array<string, mixed> $contextSections
     */
    private function streamScholarResponse(Request $request, \Survos\FolioBundle\Model\FolioContext $ctx, string $chatScope, string $conversationId, string $question, string $scopeLabel, array $contextSections): StreamedResponse
    {
        return new StreamedResponse(function () use ($request, $ctx, $chatScope, $conversationId, $question, $scopeLabel, $contextSections): void {
            $this->sendSse('start', ['conversationId' => $conversationId]);
            $streamedMarkdown = '';
            $lastHtmlLength = 0;
            $lastHtmlTime = microtime(true);

            $result = $this->chat->streamScoped(
                $ctx,
                $question,
                $scopeLabel,
                $contextSections,
                function (string $text) use (&$streamedMarkdown, &$lastHtmlLength, &$lastHtmlTime): void {
                    $streamedMarkdown .= $text;
                    $this->sendSse('delta', ['text' => $text]);

                    $now = microtime(true);
                    if (\strlen($streamedMarkdown) - $lastHtmlLength >= 240 || $now - $lastHtmlTime >= 0.4) {
                        $this->sendSse('html', ['html' => $this->renderScholarAnswerHtml($streamedMarkdown)]);
                        $lastHtmlLength = \strlen($streamedMarkdown);
                        $lastHtmlTime = $now;
                    }
                },
                $conversationId,
            );

            $this->appendChatTurn($request, $chatScope, $conversationId, $result);
            $this->sendSse('done', [
                'conversationId' => $conversationId,
                'error' => $result->error,
                'answerHtml' => $this->renderScholarAnswerHtml($result->answer),
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sendSse(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";

        if (\function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    private function renderScholarAnswerHtml(string $markdown): string
    {
        try {
            return $this->twig
                ->createTemplate('{{ answer|markdown_to_html }}')
                ->render(['answer' => $markdown]);
        } catch (\Throwable) {
            return nl2br(htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        }
    }

    /**
     * @return list<array{question: string, answer: string, error?: string|null}>
     */
    private function chatHistory(Request $request, string $scope, string $conversationId): array
    {
        if (!$request->hasSession()) {
            return [];
        }

        $history = $request->getSession()->get($this->chatHistoryKey($scope, $conversationId), []);

        return \is_array($history) ? array_values($history) : [];
    }

    /**
     * @return list<array{question: string, answer: string, error?: string|null}>
     */
    private function appendChatTurn(Request $request, string $scope, string $conversationId, \Survos\FolioBundle\Model\FolioChatResult $result): array
    {
        $history = $this->chatHistory($request, $scope, $conversationId);
        $history[] = $this->cleanContext([
            'question' => $result->question,
            'answer' => $result->answer,
            'error' => $result->error,
        ]);
        $history = array_slice($history, -20);

        if ($request->hasSession()) {
            $request->getSession()->set($this->chatHistoryKey($scope, $conversationId), $history);
        }

        return $history;
    }

    /**
     * @param array<string, mixed> $contextSections
     * @param list<array{question: string, answer: string, error?: string|null}> $chatHistory
     * @return array<string, mixed>
     */
    private function contextWithChatHistory(array $contextSections, array $chatHistory): array
    {
        if ($chatHistory === []) {
            return $contextSections;
        }

        return ['Conversation so far' => array_slice($chatHistory, -8)] + $contextSections;
    }

    private function chatHistoryKey(string $scope, string $conversationId): string
    {
        return 'survos_folio.chat.' . hash('sha256', $scope . ':' . $conversationId);
    }

    /**
     * @param list<object> $pages
     * @return array<string, mixed>
     */
    private function itemScholarContext(Core $core, Row $row, array $pages): array
    {
        $sections = [
            'Document identity' => $this->cleanContext([
                'localId' => $row->localId,
                'label' => $row->label,
                'core' => $core->code,
                'dtoType' => $row->dtoType,
                'citationUrl' => $row->getCitationUrl(),
                'pageCount' => count($pages),
            ]),
            'Source metadata' => $row->dtoData ?? [],
            'Source extras' => $row->extras ?? [],
        ];

        if (count($pages) === 1) {
            $sections['Single page context'] = $this->pageContextData($pages[0]);
        } elseif ($pages !== []) {
            $sections['Page inventory'] = array_map(
                fn (object $page): array => $this->cleanContext([
                    'seq' => $page->seq ?? null,
                    'type' => isset($page->type) && $page->type instanceof \BackedEnum ? $page->type->value : ($page->type ?? null),
                    'mediaId' => $page->mediaId ?? null,
                    'hasOcr' => isset($page->text) && is_string($page->text) && trim($page->text) !== '',
                    'hasDescription' => isset($page->denseSummary) && is_string($page->denseSummary) && trim($page->denseSummary) !== '',
                ]),
                array_slice($pages, 0, 100),
            );
            if (count($pages) > 100) {
                $sections['Page inventory note'] = sprintf('Showing metadata for the first 100 of %d pages.', count($pages));
            }
        }

        return $this->cleanContext($sections);
    }

    /** @return array<string, mixed> */
    private function pageScholarContext(Core $core, Row $row, object $page): array
    {
        return $this->cleanContext([
            'Document identity' => $this->cleanContext([
                'localId' => $row->localId,
                'label' => $row->label,
                'core' => $core->code,
                'dtoType' => $row->dtoType,
                'citationUrl' => $row->getCitationUrl(),
            ]),
            'Document source metadata' => $row->dtoData ?? [],
            'Document source extras' => $row->extras ?? [],
            'Page context' => $this->pageContextData($page),
        ]);
    }

    /** @return array<string, mixed> */
    private function pageContextData(object $page): array
    {
        return $this->cleanContext([
            'seq' => $page->seq ?? null,
            'pageIndex' => $page->pageIndex ?? null,
            'type' => isset($page->type) && $page->type instanceof \BackedEnum ? $page->type->value : ($page->type ?? null),
            'url' => $page->url ?? null,
            'mediaId' => $page->mediaId ?? null,
            'width' => $page->width ?? null,
            'height' => $page->height ?? null,
            'ocrText' => $page->text ?? null,
            'description' => $page->denseSummary ?? null,
            'ledger' => $page->ledger ?? null,
            'layout' => $page->layout ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function cleanContext(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    #[Route('/term/{setCode}/{termCode}', name: 'survos_folio_term_show')]
    public function termShow(Folio $folio, string $setCode, string $termCode): Response
    {
        $ctx = $this->folios->context($folio->code);
        $termSet = $ctx->em->find(TermSet::class, "$folio->code:$setCode")
            ?? throw $this->createNotFoundException($setCode);
        $term = $ctx->em->find(Term::class, $termSet->id . ':' . $termCode)
            ?? throw $this->createNotFoundException($termCode);

        return $this->render('@SurvosFolioBundle/folio/term.html.twig', [
            'ctx' => $ctx,
            'folio' => $folio,
            'termSet' => $termSet,
            'term' => $term,
        ]);
    }

    #[Route('/{coreCode}/{dtoType}/{localId}', name: 'survos_folio_row_show', options: ['expose' => true])]
    public function rowShow(Folio $folio, string $coreCode, string $dtoType, string $localId, Request $request): Response
    {
        // Content locale (e.g. folioCode="jarc.en") is already stripped and applied by
        // FolioRouteAttributeListener before this method runs — context() picks it up via
        // FolioService::$requestContentLocale, no need to read the request here.
        // See FolioBuildCommand's --locale build, survos-sites/scanseum#18.
        $ctx = $this->folios->context($folio->code);
        $core = $ctx->em->find(Core::class, Core::id($folio->code, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);
        $row = $ctx->em->find(Row::class, Row::id($core->id, $localId))
            ?? throw $this->createNotFoundException($localId);

        if ($row->dtoType && $dtoType !== $row->dtoType) {
            $contentLocale = $request->attributes->get('content_locale');

            return $this->redirectToRoute('survos_folio_row_show', array_filter([
                'folioCode' => $contentLocale ? "$folio->code.$contentLocale" : $folio->code,
                'coreCode' => $coreCode,
                'dtoType' => $row->dtoType,
                'localId' => $localId,
            ]));
        }

        $schemaTable = $this->schemaTable($ctx->em->getConnection(), $coreCode, $row->dtoType);
        $dtoClass = is_array($schemaTable) && is_string($schemaTable['dto_class'] ?? null) ? $schemaTable['dto_class'] : $this->dtoTypeResolver->classForType($row->dtoType);

        // Row::$dtoData is already the flattened output of FolioIngestService::splitDtoData()'s
        // $dtoClass::fromNormalized() + toMeili() round-trip done at ingest time — i.e. canonical,
        // alias-resolved field names, NOT raw source data. Re-hydrating it here gives the template a
        // typed object (dto.title, dto.creators, …) instead of attribute(row.dtoData, 'x')|default(...)
        // soup, and is the seam for per-content-type rendering later.
        $dto = $dtoClass !== null && is_a($dtoClass, BaseItemDto::class, true)
            ? $dtoClass::fromNormalized($row->dtoData ?? [])
            : null;
        $pageTableExists = $this->tableExists($ctx->em->getConnection(), 'page');
        $claims = $this->rowClaimsResolver->resolve($ctx->em->getConnection(), $row->id);
        $aiTaskRuns = $this->rowClaimsResolver->aiTaskRuns($claims);
        $links = $this->rowLinks($ctx->em, $folio->code, $coreCode, $localId);
        $terms = $this->rowTermsResolver->resolve($ctx->em, $folio->code, $row->dtoData ?? [], $row->extras ?? []);
        $adjacent = $this->adjacentRows($ctx->em->getConnection(), $core->id, $localId);

        // Fields shown elsewhere (as Terms, or as relation links) are excluded from the DTO Data
        // table on the left — same single-source bindings, so the table never repeats them.
        $termFields = array_merge(
            [],
            ...array_values(TermSetBinding::fields()),
            ...array_map(static fn (array $r): array => $r['sourceFields'], array_values(RelationBinding::relations())),
        );

        // The extras blob also shouldn't repeat fields rendered as Terms or Relations — strip the same
        // termset/relation source fields (e.g. dept/cul/med, creators/collections), plus structural
        // ones shown in the header.
        $extras = $row->extras ?? [];
        foreach ([...$termFields, 'url'] as $shownElsewhere) {
            unset($extras[$shownElsewhere]);
        }

        // row/{dtoType}.html.twig if the content type has its own template (e.g. row/postcard.html.twig,
        // which extends row/document.html.twig), else row/document.html.twig for text-bearing types,
        // else row/show.html.twig — which always exists, so resolveTemplate() never falls through empty.
        // Twig\Environment::resolveTemplate() (not $this->render()) is what lets us pass an ordered
        // list and skip the ones that don't exist, instead of hand-rolling a loader->exists() loop.
        $templateNames = array_map(
            static fn (string $name): string => "@SurvosFolioBundle/folio/row/{$name}.html.twig",
            $this->dtoTypeResolver->templateCandidates($row->dtoType),
        );

        return new Response($this->twig->resolveTemplate($templateNames)->render([
            'ctx' => $ctx,
            'folio' => $folio,
            'core' => $core,
            'row' => $row,
            'dto' => $dto,
            'dtoClass' => $dtoClass,
            'pageTableExists' => $pageTableExists,
            'claims' => $claims,
            'aiTaskRuns' => $aiTaskRuns,
            'links' => $links,
            'terms' => $terms,
            'termFields' => $termFields,
            'extras' => $extras,
            'adjacent' => $adjacent,
            'hasUxMap' => class_exists(\Symfony\UX\Map\Map::class),
        ]));
    }

    /**
     * IIIF Presentation v3 manifest built from the row's pages — one canvas per
     * page, in seq order. Feeds the diva.js viewer on the doc page. Higher priority
     * than {@see rowShow()} so `…/{localId}/manifest.json` isn't swallowed by the
     * generic `…/{dtoType}/{localId}` row route.
     */
    #[Route('/{coreCode}/{localId}/manifest.json', name: 'survos_folio_iiif_manifest', priority: 10)]
    public function iiifManifest(Folio $folio, string $coreCode, string $localId): Response
    {
        $ctx = $this->folios->context($folio->code);
        $core = $ctx->em->find(Core::class, Core::id($folio->code, $coreCode)) ?? throw $this->createNotFoundException($coreCode);
        $row = $ctx->em->find(Row::class, Row::id($core->id, $localId)) ?? throw $this->createNotFoundException($localId);

        $manifestUrl = $this->generateUrl(
            'survos_folio_iiif_manifest',
            ['folioCode' => $folio->code, 'coreCode' => $coreCode, 'localId' => $localId],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        // Route through imgproxy (the 'archive' preset: original resolution, metadata kept)
        // rather than embedding the raw source URL — many source CDNs (museum/library
        // image hosts) send no Access-Control-Allow-Origin header, which breaks IIIF
        // viewers like diva.js/OpenSeadragon that load canvas images in CORS mode.
        // imgproxy re-serves same-origin, sidestepping the third-party CORS gap entirely.
        $format = $this->imgproxy ? 'image/webp' : 'image/jpeg';

        $images = [];
        if ($this->tableExists($ctx->em->getConnection(), 'page')) {
            // Pages are the canonical imagery — one canvas per page, in seq order.
            foreach ($row->pages as $page) {
                $images[] = [
                    'url' => $this->imgproxy?->resizePreset($page->url, 'archive') ?? $page->url,
                    'width' => $page->width ?? 1000,
                    'height' => $page->height ?? 1000,
                    'format' => $format,
                ];
            }
        }
        if ($images === []) {
            // No pages: fall back to the row's own image.
            $data = $row->dtoData ?? [];
            $url = $data['largeImageUrl'] ?? $data['thumbnailUrl'] ?? null;
            if (is_string($url) && $url !== '') {
                $images = [['url' => $this->imgproxy?->resizePreset($url, 'archive') ?? $url, 'width' => (int) ($data['width'] ?? 1000), 'height' => (int) ($data['height'] ?? 1000), 'format' => $format]];
            }
        }

        $canvases = [];
        $seq = 0;
        foreach ($images as $img) {
            ++$seq;
            $canvasId = $manifestUrl.'/canvas/'.$seq;
            $canvases[] = [
                'id' => $canvasId,
                'type' => 'Canvas',
                'label' => ['none' => ['p. '.$seq]],
                'height' => $img['height'],
                'width' => $img['width'],
                'items' => [[
                    'id' => $canvasId.'/page',
                    'type' => 'AnnotationPage',
                    'items' => [[
                        'id' => $canvasId.'/annotation',
                        'type' => 'Annotation',
                        'motivation' => 'painting',
                        'target' => $canvasId,
                        'body' => [
                            'id' => $img['url'],
                            'type' => 'Image',
                            'format' => $img['format'],
                            'height' => $img['height'],
                            'width' => $img['width'],
                        ],
                    ]],
                ]],
            ];
        }

        return $this->json([
            '@context' => 'http://iiif.io/api/presentation/3/context.json',
            'id' => $manifestUrl,
            'type' => 'Manifest',
            'label' => ['none' => [$row->label ?: $localId]],
            'items' => $canvases,
        ]);
    }

    /**
     * Image-core rows linked to a doc, in sequence order, with a paint URL.
     *
     * @return list<array{url:string,width:int,height:int,format:string,localId:string,sequence:int}>
     */
    private function linkedImageRows(\Doctrine\ORM\EntityManagerInterface $em, string $folioCode, string $coreCode, string $localId): array
    {
        $rows = [];
        foreach ($this->rowLinks($em, $folioCode, $coreCode, $localId) as $link) {
            $target = $link['row'];
            if ($link['core'] !== 'image' || !$target instanceof Row) {
                continue;
            }
            $data = $target->dtoData ?? [];
            $url = $data['largeImageUrl'] ?? $data['url'] ?? $data['thumbnailUrl'] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }
            $isPdf = stripos((string) ($data['objectType'] ?? ''), 'pdf') !== false;
            $rows[] = [
                'url' => $url,
                'width' => (int) ($data['width'] ?? 1000),
                'height' => (int) ($data['height'] ?? 1000),
                'format' => $isPdf ? 'application/pdf' : 'image/jpeg',
                'localId' => (string) $link['localId'],
                'sequence' => (int) ($data['sequence'] ?? 0),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        return $rows;
    }

    /**
     * @return list<array{direction:string, label:?string, code:string, core:string, localId:string, row:?Row}>
     */
    private function rowLinks(\Doctrine\ORM\EntityManagerInterface $em, string $folioCode, string $coreCode, string $localId): array
    {
        $linkRows = $em->getRepository(Link::class)->createQueryBuilder('r')
            ->join('r.type', 't')
            ->join('t.folio', 'f')
            ->where('f.code = :folioCode')
            ->andWhere('(r.leftCore = :coreCode AND r.leftId = :localId) OR (r.rightCore = :coreCode AND r.rightId = :localId)')
            ->setParameter('folioCode', $folioCode)
            ->setParameter('coreCode', $coreCode)
            ->setParameter('localId', $localId)
            ->orderBy('t.code', 'ASC')
            ->getQuery()
            ->getResult();

        $links = [];
        foreach ($linkRows as $link) {
            \assert($link instanceof Link);
            $outgoing = $link->leftCore === $coreCode && $link->leftId === $localId;
            $targetCore = $outgoing ? $link->rightCore : $link->leftCore;
            $targetId = $outgoing ? $link->rightId : $link->leftId;
            $target = $em->find(Row::class, Row::id(Core::id($folioCode, $targetCore), $targetId));
            $links[] = [
                'direction' => $outgoing ? 'out' : 'in',
                'label' => $outgoing ? $link->type->label : $link->type->reverseLabel,
                'code' => $outgoing ? $link->type->code : ($link->type->reverseCode ?? $link->type->code),
                'core' => $targetCore,
                'localId' => $targetId,
                'row' => $target instanceof Row ? $target : null,
            ];
        }

        return $links;
    }

    #[Route('/{coreCode}', name: 'survos_folio_core')]
    #[Route('/{coreCode}/{dtoType}', name: 'survos_folio_core_dto', options: ['expose' => true])]
    public function core(Folio $folio, string $coreCode, Request $request, ?string $dtoType = null): Response
    {
        $ctx       = $this->folios->context($folio->code);
        $core      = $ctx->em->find(Core::class, Core::id($folio->code, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);

        $dtoChoices = $this->dtoChoices($ctx->em->getConnection()->executeQuery(
            'SELECT dto_type, COUNT(*) AS cnt FROM item WHERE core_id = ? GROUP BY dto_type ORDER BY cnt DESC',
            [$core->id],
        )->fetchAllAssociative());

        $selectedDto = $dtoType ?? '';
        $schemaTable = $selectedDto !== '' ? $this->schemaTable($ctx->em->getConnection(), $coreCode, $selectedDto) : null;
        $selectedDtoClass = is_array($schemaTable) && is_string($schemaTable['dto_class'] ?? null) ? $schemaTable['dto_class'] : $this->dtoTypeResolver->classForType($selectedDto);
        $populated   = array_flip($core->fieldSummary ?? []);
        $columns     = $schemaTable
            ? $this->schemaColumns($ctx->em->getConnection(), (string) $schemaTable['id'])
            : ($selectedDtoClass
                ? array_values(array_filter(
                    $this->dtoColumns($selectedDtoClass),
                    fn (string $col) => isset($populated[$col]) || $col === 'id',
                ))
                : []);

        return $this->render('@SurvosFolioBundle/folio/core.html.twig', [
            'ctx'         => $ctx,
            'folio'       => $folio,
            'core'        => $core,
            'dtoStats'    => $dtoChoices,
            'dtoChoices'   => $dtoChoices,
            'selectedDto' => $selectedDto,
            'rowClass'     => Row::class,
            'columns'     => $columns,
            'schemaTable' => $schemaTable,
        ]);
    }

    /**
     * Previous/next document within the same core, in build (rowid) order — keyset navigation done
     * with SQLite LAG/LEAD so both neighbours come back in a single pass (no offset, no loading the
     * list). v1 uses intrinsic order; a later version can honour the active search/filter context.
     *
     * @return array{prev: ?array{localId: string, dtoType: string}, next: ?array{localId: string, dtoType: string}}
     */
    private function adjacentRows(Connection $conn, string $coreId, string $localId): array
    {
        $row = $conn->fetchAssociative(
            'SELECT prev_id, prev_type, next_id, next_type FROM (
                SELECT local_id,
                       LAG(local_id)  OVER (ORDER BY rowid) AS prev_id,
                       LAG(dto_type)  OVER (ORDER BY rowid) AS prev_type,
                       LEAD(local_id) OVER (ORDER BY rowid) AS next_id,
                       LEAD(dto_type) OVER (ORDER BY rowid) AS next_type
                FROM item WHERE core_id = :core
            ) WHERE local_id = :current',
            ['core' => $coreId, 'current' => $localId],
        ) ?: [];

        $mk = static fn (?string $id, ?string $type): ?array => null === $id || '' === $id
            ? null
            : ['localId' => $id, 'dtoType' => $type ?: 'row'];

        return [
            'prev' => $mk($row['prev_id'] ?? null, $row['prev_type'] ?? null),
            'next' => $mk($row['next_id'] ?? null, $row['next_type'] ?? null),
        ];
    }

    private function tableExists(Connection $conn, string $table): bool
    {
        try {
            return $conn->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $stats
     * @return list<array{type: string|null, label: string, count: int}>
     */
    private function dtoChoices(array $stats): array
    {
        $choices = [];
        foreach ($stats as $stat) {
            $type = is_string($stat['dto_type'] ?? null) && $stat['dto_type'] !== '' ? $stat['dto_type'] : null;
            $choices[] = [
                'type' => $type,
                'label' => $this->dtoTypeResolver->labelForType($type),
                'count' => (int) $stat['cnt'],
            ];
        }

        return $choices;
    }

    private function schemaTable(\Doctrine\DBAL\Connection $connection, string $coreCode, ?string $dtoType): ?array
    {
        if ($dtoType === null || $dtoType === '') {
            return null;
        }

        $row = $connection->executeQuery(
            "SELECT id, name, dto_class FROM schema_table WHERE kind = 'dto' AND core_code = ? AND dto_type = ? LIMIT 1",
            [$coreCode, $dtoType],
        )->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /** @return list<string> */
    private function schemaColumns(\Doctrine\DBAL\Connection $connection, string $tableId): array
    {
        return array_values(array_filter(
            $connection->executeQuery(
                'SELECT name FROM schema_property WHERE table_id = ? AND visible = 1 ORDER BY position, name',
                [$tableId],
            )->fetchFirstColumn(),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));
    }

    private function dtoColumns(string $dtoClass): array
    {
        if (!class_exists($dtoClass)) {
            return [];
        }
        $props = [];
        foreach ((new \ReflectionClass($dtoClass))->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
            if (!$p->isStatic()) {
                $props[] = $p->getName();
            }
        }
        return $props;
    }

    private function formatDdl(string $sql): string
    {
        $open = strpos($sql, '(');
        if ($open === false) {
            return $sql;
        }

        $head = substr($sql, 0, $open + 1);
        $body = substr($sql, $open + 1, -1);
        $parts = [];
        $depth = 0;
        $current = '';

        for ($i = 0, $len = strlen($body); $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $head . "\n    " . implode(",\n    ", $parts) . "\n)";
    }
}
