<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Survos\FolioBundle\Attribute\FolioContext;
use Survos\FolioBundle\Entity\Core;
use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[FolioContext]
final class FolioSearchController extends AbstractController
{
    public function __construct(private readonly FolioService $folios)
    {
    }

    // $coreCode/$dtoType's defaults (?string = null) make Symfony infer BOTH as optional trailing
    // segments on every route below, so e.g. core_type_search's compiled pattern matches bare
    // /{provider}/{dataset}/search URLs too, not just 4-segment ones — same shadowing class as
    // FolioController::map()'s survos_folio_map/survos_folio_map_filtered. Priority must run from
    // the SHORTEST literal path to the longest, so each route only ever wins for the segment count
    // it actually names — otherwise the longest pattern (checked first if priorities are reversed)
    // always wins, and survos_folio_search never actually resolves (breaking anything that compares
    // by route name, e.g. TenantMenu's "active" highlighting).
    #[Route('/{provider}/{dataset}/search', name: 'survos_folio_search', priority: 40)]
    #[Route('/{provider}/{dataset}/search/{coreCode}', name: 'survos_folio_core_search', priority: 30)]
    #[Route('/{provider}/{dataset}/search/{coreCode}/{dtoType}', name: 'survos_folio_core_type_search', priority: 20)]
    // Public slug alias (survos-sites/scanseum#12), resolved by FolioRouteAttributeListener —
    // same mechanism as survos_folio_show_slug. Explicit priority: this is exactly 2 segments,
    // same shape as survos_folio_show's fully-dynamic /{provider}/{dataset} (priority 0 by
    // default), which would otherwise swallow it first for any /{x}/search URL.
    #[Route('/{slug}/search', name: 'survos_folio_search_slug', requirements: ['slug' => '[^/]+'], priority: 10)]
    public function __invoke(string $provider, string $dataset, ?string $coreCode = null, ?string $dtoType = null): Response
    {
        $folioCode = "$provider/$dataset";
        // Content locale (e.g. dataset="jarc.en") is stripped and applied by
        // FolioRouteAttributeListener before this method runs — context() picks it up via
        // FolioService::$requestContentLocale. See survos-sites/scanseum#18.
        $ctx = $this->folios->context($folioCode);

        return $this->render('@SurvosFolioBundle/folio/search.html.twig', [
            'ctx' => $ctx,
            'folio' => $ctx->em->find(Folio::class, $ctx->folioCode),
            'cores' => $ctx->em->getRepository(Core::class)->findBy([], ['code' => 'ASC']),
            'selectedCore' => $coreCode,
            'selectedDtoType' => $dtoType,
        ]);
    }
}
