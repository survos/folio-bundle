<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Survos\FolioBundle\Attribute\FolioContext;
use Survos\FolioBundle\Entity\{Core,Row};
use Survos\FolioBundle\Service\{FolioDtoTypeResolver,FolioService};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/folio')]
#[FolioContext]
final class FolioController extends AbstractController
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioDtoTypeResolver $dtoTypeResolver,
    ) {}

    #[Route('/{provider}/{dataset}', name: 'survos_folio_show')]
    public function show(string $provider, string $dataset): Response
    {
        $ctx = $this->folios->context("$provider/$dataset");
        return $this->render('@SurvosFolioBundle/folio/show.html.twig', [
            'ctx'   => $ctx,
            'cores' => $ctx->em->getRepository(Core::class)->findBy([], ['code' => 'ASC']),
        ]);
    }

    #[Route('/{provider}/{dataset}/{coreCode}', name: 'survos_folio_core')]
    public function core(string $provider, string $dataset, string $coreCode, Request $request): Response
    {
        $folioCode = "$provider/$dataset";
        $ctx       = $this->folios->context($folioCode);
        $core      = $ctx->em->find(Core::class, Core::id($folioCode, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);

        $dtoStats = $ctx->em->getConnection()->executeQuery(
            'SELECT dto_type, COUNT(*) AS cnt FROM item WHERE core_id = ? GROUP BY dto_type ORDER BY cnt DESC',
            [$core->id],
        )->fetchAllAssociative();

        $dtoChoices = [];
        foreach ($dtoStats as $stat) {
            $type = is_string($stat['dto_type'] ?? null) ? $stat['dto_type'] : null;
            $dtoChoices[] = [
                'type' => $type,
                'label' => $this->dtoTypeResolver->labelForType($type),
                'count' => (int) $stat['cnt'],
            ];
        }

        $selectedDto = $request->query->getString('dto');
        $selectedDtoClass = $this->dtoTypeResolver->classForType($selectedDto);
        $populated   = array_flip($core->fieldSummary ?? []);
        $columns     = $selectedDtoClass
            ? array_values(array_filter(
                $this->dtoColumns($selectedDtoClass),
                fn (string $col) => isset($populated[$col]) || $col === 'id',
            ))
            : [];

        return $this->render('@SurvosFolioBundle/folio/core.html.twig', [
            'ctx'         => $ctx,
            'core'        => $core,
            'dtoStats'    => $dtoStats,
            'dtoChoices'   => $dtoChoices,
            'selectedDto' => $selectedDto,
            'rowClass'     => Row::class,
            'columns'     => $columns,
        ]);
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
}
