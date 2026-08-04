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

        // Powers the timeline ruler's "seek to year" -- >=, not =, so seeking lands you at
        // that point in the same year-ascending sort (matching fortepan.hu's own "jump to
        // year" semantics) rather than only ever showing the one exact year, which could be
        // empty for it while still having plenty of nearby photos.
        $yearMin = null;
        if (isset($filters['yearMin']) && is_numeric($filters['yearMin'])) {
            $yearMin = (int) $filters['yearMin'];
        }

        // Deliberately crude (LIKE over label + the whole dto_data JSON blob, not FolioRowSearch's
        // real Meilisearch full-text index) -- a debug tool for combining with the timeline's
        // yearMin while that Meilisearch integration stays untouched, not a search feature in its
        // own right (2026-07-31).
        $q = null;
        if (isset($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
            $q = trim($filters['q']);
        }

        $request      = $this->requestStack->getCurrentRequest();
        $page         = max(1, (int) ($request?->query->get('page', 1) ?? 1));
        $itemsPerPage = max(1, (int) ($request?->query->get('itemsPerPage', 50) ?? 50));

        // Sort by the year (nulls last, so undated rows don't cluster at the front) rather than
        // local_id insertion order -- same "year:asc = Year Old-New" default FolioRowSearch's
        // faceted search already uses. Doctrine ORM/DQL has no json_extract() support, so this
        // goes through the raw connection like FolioRowSearch and TenantController::timelineData()
        // already do for the same dto_data-is-JSON reason; Row entities are then hydrated by id
        // and reordered to match, since a WHERE id IN (...) doesn't preserve that order itself.
        //
        // sort_key (materialized column), not json_extract(dto_data, '$.year') -- see
        // FolioFtsIndexer::ensurePrimarySortColumn() for why (measured ~5x faster end to end on
        // mus/fortepan's 219k rows, 2026-08-04). NULLS LAST lets SQLite use the covering index
        // directly instead of falling back to a temp sort.
        $conn = $ctx->em->getConnection();
        $where = ['core_id = :coreId'];
        $params = ['coreId' => $core->id];
        if ($dtoType) {
            $where[] = 'dto_type = :dtoType';
            $params['dtoType'] = $dtoType;
        }
        if ($yearMin !== null) {
            $where[] = 'sort_key >= :yearMin';
            $params['yearMin'] = $yearMin;
        }
        if ($q !== null) {
            $where[] = '(label LIKE :q OR dto_data LIKE :q)';
            $params['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $conn->executeQuery("SELECT COUNT(*) FROM item WHERE $whereSql", $params)->fetchOne();

        $localIds = $conn->executeQuery(
            sprintf(
                'SELECT local_id FROM item WHERE %s
                 ORDER BY sort_key ASC NULLS LAST, local_id ASC
                 LIMIT %d OFFSET %d',
                $whereSql,
                $itemsPerPage,
                ($page - 1) * $itemsPerPage,
            ),
            $params,
        )->fetchFirstColumn();

        if ($localIds === []) {
            return new TraversablePaginator(new \ArrayIterator([]), $page, $itemsPerPage, $total);
        }

        $compositeIds = array_map(static fn (string $localId): string => Row::id($core->id, $localId), $localIds);
        $rowsById = [];
        foreach ($ctx->em->getRepository(Row::class)->findBy(['id' => $compositeIds]) as $row) {
            $rowsById[$row->id] = $row;
        }
        $results = array_values(array_filter(array_map(
            static fn (string $id): ?Row => $rowsById[$id] ?? null,
            $compositeIds,
        )));

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
