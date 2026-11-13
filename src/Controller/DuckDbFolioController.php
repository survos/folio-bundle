<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Service\DuckDbFolioConnection;
use Survos\FolioBundle\Service\DuckDbFolioMap;
use Survos\FolioBundle\Service\DuckDbFolioView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Optional folio manifestation. Existing ES/SQLite entrypoints retain their reader. */
#[Route('/{folioCode}/duckdb', requirements: ['folioCode' => '[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+'], defaults: ['_folio_reader' => 'duckdb'])]
final class DuckDbFolioController extends AbstractController
{
    public function __construct(private readonly DuckDbFolioConnection $connection, private readonly DuckDbFolioView $view, private readonly DuckDbFolioMap $maps)
    {
    }

    #[Route('/search', name: 'survos_folio_duckdb_search', methods: ['GET'], priority: 60)]
    public function search(string $folioCode): Response
    {
        // Check the registered manifestation before rendering the shared search component.
        $this->connection->connect(['folioCode' => $folioCode]);

        return $this->render('@SurvosFolioBundle/folio/duckdb/search.html.twig', [
            'folio' => new Folio($folioCode), 'reader' => 'duckdb',
            'hasCoordinates' => $this->maps->hasCoordinates($folioCode),
            'hitTemplate' => '@SurvosFolioBundle/search/hits/folio_duckdb.html.twig',
        ]);
    }

    #[Route('/map/{filter}', name: 'survos_folio_duckdb_map_filtered', requirements: ['filter' => '[A-Za-z0-9_-]+'], priority: 70)]
    #[Route('/map', name: 'survos_folio_duckdb_map', priority: 70)]
    public function map(string $folioCode, Request $request, ?string $filter = null): Response
    {
        $this->connection->connect(['folioCode' => $folioCode]);
        $filter ??= rtrim(strtr(base64_encode(http_build_query($request->query->all())), '+/', '-_'), '=');

        return $this->render('@SurvosFolioBundle/folio/map.html.twig', [
            'folio' => new Folio($folioCode), 'reader' => 'duckdb', 'filter' => $filter,
            'hasUxMap' => class_exists(\Symfony\UX\Map\Map::class),
            'geojsonRoute' => 'survos_folio_duckdb_map_geojson',
            'geojsonFilteredRoute' => 'survos_folio_duckdb_map_geojson_filtered',
            'rowUrlTemplate' => '', 'slideshowBaseUrl' => null,
        ]);
    }

    #[Route('/map.geojson/{filter}/{zoom}', name: 'survos_folio_duckdb_map_geojson_filtered', requirements: ['filter' => '[A-Za-z0-9_-]+', 'zoom' => '\d+'], priority: 70)]
    #[Route('/map.geojson/{zoom}', name: 'survos_folio_duckdb_map_geojson', requirements: ['zoom' => '\d+'], priority: 70)]
    public function mapGeoJson(string $folioCode, int $zoom, Request $request, ?string $filter = null): JsonResponse
    {
        $source = $request->query->all();
        if ($filter) {
            $decoded = base64_decode(strtr($filter, '-_', '+/'), true);
            if (false === $decoded) {
                throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid map filter.');
            }
            parse_str($decoded, $source);
        }
        $features = $this->maps->features($folioCode, $source, $request->query->getString('bbox'));
        foreach ($features as &$feature) {
            $feature['properties']['url'] = $this->generateUrl('survos_folio_duckdb_row_show', [
                'folioCode' => $folioCode, 'coreCode' => $feature['properties']['coreCode'],
                'localId' => $feature['properties']['id'],
            ]);
        }
        unset($feature);

        return new JsonResponse(['type' => 'FeatureCollection', 'features' => $features]);
    }

    #[Route('/{coreCode}/{localId}', name: 'survos_folio_duckdb_row_show', requirements: ['localId' => '.+'], methods: ['GET'], priority: 50)]
    public function show(string $folioCode, string $coreCode, string $localId): Response
    {
        return $this->render('@SurvosFolioBundle/folio/row/duckdb.html.twig', $this->view->item($folioCode, $coreCode, $localId));
    }
}
