<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Service\DuckDbFolioConnection;
use Survos\FolioBundle\Service\DuckDbFolioMap;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** A folio's search page. The folio is read from its dfolio, like every public folio page. */
final class FolioSearchController extends AbstractController
{
    public function __construct(private readonly DuckDbFolioConnection $connection, private readonly DuckDbFolioMap $maps)
    {
    }

    // $coreCode/$dtoType's defaults (?string = null) make Symfony infer BOTH as optional trailing
    // segments on every route below, so e.g. core_type_search's compiled pattern matches bare
    // /{folioCode}/search URLs too, not just 4-segment ones — same shadowing class as
    // FolioController::map()'s survos_folio_map/survos_folio_map_filtered. Priority must run from
    // the SHORTEST literal path to the longest, so each route only ever wins for the segment count
    // it actually names — otherwise the longest pattern (checked first if priorities are reversed)
    // always wins, and survos_folio_search never actually resolves (breaking anything that compares
    // by route name, e.g. TenantMenu's "active" highlighting).
    //
    // The bare-slug form travels through the same {folioCode} param as a real "provider/dataset"
    // code — FolioRouteAttributeListener resolves it before this action runs, same mechanism as
    // FolioController::show(). No separate {slug} route needed.
    #[Route('/{folioCode}/search', name: 'survos_folio_search', requirements: ['folioCode' => FolioController::FOLIO_CODE_PATTERN], defaults: ['_folio_reader' => 'duckdb'], priority: 40)]
    #[Route('/{folioCode}/search/{coreCode}', name: 'survos_folio_core_search', requirements: ['folioCode' => FolioController::FOLIO_CODE_PATTERN], defaults: ['_folio_reader' => 'duckdb'], priority: 30)]
    #[Route('/{folioCode}/search/{coreCode}/{dtoType}', name: 'survos_folio_core_type_search', requirements: ['folioCode' => FolioController::FOLIO_CODE_PATTERN], defaults: ['_folio_reader' => 'duckdb'], priority: 20)]
    public function __invoke(string $folioCode, ?string $coreCode = null, ?string $dtoType = null): Response
    {
        // The search has one address per folio. A type in the path becomes its Type filter; a core has no filter.
        if (null !== $coreCode) {
            return $this->redirectToRoute('survos_folio_search', ['folioCode' => $folioCode] + (null !== $dtoType ? ['contentType' => $dtoType] : []));
        }
        // 404 here, before the search component runs, when the folio has no dfolio.
        $this->connection->connect(['folioCode' => $folioCode]);

        return $this->render('@SurvosFolioBundle/folio/duckdb/search.html.twig', [
            'folio' => new Folio($folioCode), 'reader' => 'duckdb',
            'hasCoordinates' => $this->maps->hasCoordinates($folioCode),
            'hitTemplate' => '@SurvosFolioBundle/search/hits/folio_duckdb.html.twig',
        ]);
    }
}
