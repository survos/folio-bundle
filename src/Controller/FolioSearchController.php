<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Survos\FolioBundle\Entity\Core;
use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FolioSearchController extends AbstractController
{
    public function __construct(private readonly FolioService $folios)
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
    #[Route('/{folioCode}/search', name: 'survos_folio_search', requirements: ['folioCode' => FolioController::FOLIO_CODE_PATTERN], priority: 40)]
    #[Route('/{folioCode}/search/{coreCode}', name: 'survos_folio_core_search', requirements: ['folioCode' => FolioController::FOLIO_CODE_PATTERN], priority: 30)]
    #[Route('/{folioCode}/search/{coreCode}/{dtoType}', name: 'survos_folio_core_type_search', requirements: ['folioCode' => FolioController::FOLIO_CODE_PATTERN], priority: 20)]
    public function __invoke(Folio $folio, ?string $coreCode = null, ?string $dtoType = null): Response
    {
        $ctx = $this->folios->context($folio->code);

        return $this->render('@SurvosFolioBundle/folio/search.html.twig', [
            'ctx' => $ctx,
            'folio' => $folio,
            'cores' => $ctx->em->getRepository(Core::class)->findBy([], ['code' => 'ASC']),
            'selectedCore' => $coreCode,
            'selectedDtoType' => $dtoType,
        ]);
    }
}
