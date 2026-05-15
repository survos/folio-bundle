<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Controller;

use Survos\FolioBundle\Entity\{Core,Row};
use Survos\FolioBundle\Service\FolioService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/folio')]
final class FolioController extends AbstractController
{
    #[Route('/{provider}/{dataset}', name: 'survos_folio_show')]
    public function show(string $provider, string $dataset, FolioService $folios): Response
    {
        $ctx = $folios->context("$provider/$dataset");
        return $this->render('@SurvosFolioBundle/folio/show.html.twig', [
            'ctx'   => $ctx,
            'cores' => $ctx->em->getRepository(Core::class)->findBy([], ['code' => 'ASC']),
        ]);
    }

    #[Route('/{provider}/{dataset}/{coreCode}', name: 'survos_folio_core')]
    public function core(
        string $provider,
        string $dataset,
        string $coreCode,
        Request $request,
        FolioService $folios,
    ): Response {
        $folioCode  = "$provider/$dataset";
        $ctx        = $folios->context($folioCode);
        $core       = $ctx->em->find(Core::class, Core::id($folioCode, $coreCode))
            ?? throw $this->createNotFoundException($coreCode);

        // Distinct DTO classes + counts for page nav.
        $dtoStats = $ctx->em->getConnection()->executeQuery(
            'SELECT dto_class, COUNT(*) AS cnt FROM item WHERE core_id = ? GROUP BY dto_class ORDER BY cnt DESC',
            [$core->id],
        )->fetchAllAssociative();

        $selectedDto = $request->query->get('dtoClass');
        $columns     = $selectedDto ? $this->dtoColumns($selectedDto) : [];

        $qb = $ctx->em->getRepository(Row::class)
            ->createQueryBuilder('r')
            ->where('r.core = :core')->setParameter('core', $core)
            ->orderBy('r.localId', 'ASC')
            ->setMaxResults(50);

        if ($selectedDto) {
            $qb->andWhere('r.dtoClass = :dto')->setParameter('dto', $selectedDto);
        }

        $rows = $qb->getQuery()->getResult();

        return $this->render('@SurvosFolioBundle/folio/core.html.twig', [
            'ctx'         => $ctx,
            'core'        => $core,
            'dtoStats'    => $dtoStats,
            'selectedDto' => $selectedDto,
            'columns'     => $columns,
            'rows'        => array_map(fn (Row $r) => $this->processRow($r, $columns), $rows),
        ]);
    }

    /** @return string[] public non-static property names from the full inheritance chain */
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

    /** @param string[] $columns DTO property names; empty = show all data */
    private function processRow(Row $row, array $columns): array
    {
        $data     = $row->dtoData;
        $dtoClass = $row->dtoClass;

        // If no columns hint, derive from this row's dtoClass.
        if (!$columns && $dtoClass) {
            $columns = $this->dtoColumns($dtoClass);
        }

        $dto    = [];
        $extras = $data;

        foreach ($columns as $name) {
            if (array_key_exists($name, $extras)) {
                $val = $extras[$name];
                unset($extras[$name]);
                if ($val !== null && $val !== '' && $val !== []) {
                    $dto[$name] = $val;
                }
            }
        }

        // When no DTO class at all, show everything as dto.
        if (!$columns) {
            $dto    = $data;
            $extras = [];
        }

        $extras = array_filter($extras, fn ($v) => $v !== null && $v !== '' && $v !== []);

        return [
            'row'      => $row,
            'dtoClass' => $dtoClass,
            'dto'      => $dto,
            'extras'   => $extras,
        ];
    }
}
