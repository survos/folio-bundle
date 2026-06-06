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

    #[Route('/folio/{provider}/{dataset}/search', name: 'survos_folio_search', priority: 20)]
    public function __invoke(string $provider, string $dataset): Response
    {
        $folioCode = "$provider/$dataset";
        $ctx = $this->folios->context($folioCode);

        return $this->render('@SurvosFolioBundle/folio/search.html.twig', [
            'ctx' => $ctx,
            'folio' => $ctx->em->find(Folio::class, $ctx->folioCode),
            'cores' => $ctx->em->getRepository(Core::class)->findBy([], ['code' => 'ASC']),
        ]);
    }
}
