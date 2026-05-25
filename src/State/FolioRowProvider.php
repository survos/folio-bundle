<?php

declare(strict_types=1);

namespace Survos\FolioBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use Survos\FolioBundle\Entity\{Core, Row};
use Survos\FolioBundle\Service\FolioService;
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

final class FolioRowProvider implements ProviderInterface
{
    public function __construct(
        private readonly FolioService $folioService,
        private readonly RequestStack $requestStack,
        private readonly ?ImgproxyUrlBuilder $imgproxyUrlBuilder = null,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable|object
    {
        $provider  = $uriVariables['provider'] ?? '';
        $dataset   = $uriVariables['dataset'] ?? '';
        $coreCode  = $uriVariables['coreCode'] ?? 'obj';
        $folioCode = "$provider/$dataset";

        $ctx  = $this->folioService->context($folioCode);
        $core = $ctx->em->find(Core::class, Core::id($folioCode, $coreCode));
        if (!$core) {
            return new TraversablePaginator(new \ArrayIterator([]), 1, 50, 0);
        }

        if (isset($uriVariables['localId']) && is_string($uriVariables['localId'])) {
            $row = $ctx->em->find(Row::class, Row::id($core->id, $uriVariables['localId']));
            if ($row instanceof Row) {
                $this->resolveThumbnails([$row]);
            }

            return $row;
        }

        $filters = $context['filters'] ?? [];
        $dtoType = null;
        if (isset($filters['dtoType']) && is_string($filters['dtoType'])) {
            $dtoType = $filters['dtoType'];
        } elseif (isset($filters['dto']) && is_string($filters['dto'])) {
            $dtoType = $filters['dto'];
        }

        $request      = $this->requestStack->getCurrentRequest();
        $page         = max(1, (int) ($request?->query->get('page', 1) ?? 1));
        $itemsPerPage = max(1, (int) ($request?->query->get('itemsPerPage', 50) ?? 50));

        $qb = $ctx->em->getRepository(Row::class)
            ->createQueryBuilder('r')
            ->where('r.core = :core')
            ->setParameter('core', $core)
            ->orderBy('r.localId', 'ASC');

        if ($dtoType) {
            $qb->andWhere('r.dtoType = :dto')->setParameter('dto', $dtoType);
        }

        $total = (int) (clone $qb)
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $results = $qb
            ->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage)
            ->getQuery()
            ->getResult();

        $this->resolveThumbnails($results);

        return new TraversablePaginator(new \ArrayIterator($results), $page, $itemsPerPage, $total);
    }

    /**
     * @param Row[] $rows
     */
    private function resolveThumbnails(array $rows): void
    {
        if ($this->imgproxyUrlBuilder === null) {
            return;
        }

        foreach ($rows as $row) {
            $source = $row->getThumbnailSource();
            if ($source) {
                $row->setResolvedThumbnailUrl($this->imgproxyUrlBuilder->resizePreset($source, 'thumb'));
            }
        }
    }
}
