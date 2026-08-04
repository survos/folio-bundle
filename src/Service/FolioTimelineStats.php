<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Entity\Core;

/**
 * Year range + per-year counts for a folio/core -- the same shape openfoto's own
 * TenantController::timelineData() computes for the tenant gallery/photo pages, extracted here
 * as a reusable bundle-level service so folio-bundle's own generic search page (and any other
 * bundle template) can seed a timeline widget too, without an app having to duplicate the SQL.
 * Deliberately just the numbers (min/max/counts) -- NOT the "slides" hero-strip sample
 * TenantController's own version also builds; that's presentation-specific to openfoto's
 * fortepan-styled pages, this is the generic, reusable part.
 *
 * @see \App\Controller\TenantController::timelineData() in openfoto for the sibling
 *      implementation this doesn't replace (that one also builds a capped "slides" sample this
 *      doesn't need); a real consolidation is the longer-term fix if a third caller needs this
 *      exact shape.
 */
final class FolioTimelineStats
{
    public function __construct(private readonly FolioService $folioService)
    {
    }

    /** @return array{min: int, max: int, counts: array<int, int>}|null */
    public function forFolio(string $folioCode, ?string $coreCode = null): ?array
    {
        $ctx = $this->folioService->context($folioCode);
        $core = $this->resolveCore($ctx->em, $folioCode, $coreCode);
        if ($core === null) {
            return null;
        }

        $conn = $ctx->em->getConnection();
        // Materialized column, not json_extract(d.dto_data, '$.year') -- see
        // FolioFtsIndexer::ensurePrimarySortColumn() (measured ~5x faster end to end on
        // mus/fortepan's 219k rows, 2026-08-04).
        $yearExpr = 'd.sort_key';
        $where = 'd.core_id = :core';
        $params = ['core' => $core->id];

        $ends = $conn->executeQuery(
            sprintf('SELECT MIN(%1$s) AS lo, MAX(%1$s) AS hi FROM item d WHERE %2$s AND %1$s IS NOT NULL', $yearExpr, $where),
            $params,
        )->fetchAssociative();

        if ($ends === false || $ends['lo'] === null || $ends['hi'] === null || $ends['lo'] == $ends['hi']) {
            return null;
        }

        $counts = $conn->executeQuery(
            sprintf('SELECT %1$s AS year, COUNT(*) AS n FROM item d WHERE %2$s AND %1$s IS NOT NULL GROUP BY %1$s', $yearExpr, $where),
            $params,
        )->fetchAllKeyValue();

        return [
            'min' => (int) $ends['lo'],
            'max' => (int) $ends['hi'],
            'counts' => array_map(static fn (mixed $n): int => (int) $n, $counts),
        ];
    }

    /** Same "obj" core code preference, else the core with the most rows, as
     * App\Controller\TenantController::primaryCore() -- kept independent rather than shared
     * since that method is app-private and Core lookup is a two-line query either way. */
    private function resolveCore(\Doctrine\ORM\EntityManagerInterface $em, string $folioCode, ?string $coreCode): ?Core
    {
        if ($coreCode !== null) {
            return $em->find(Core::class, Core::id($folioCode, $coreCode));
        }

        $objCore = $em->find(Core::class, Core::id($folioCode, 'obj'));
        if ($objCore !== null) {
            return $objCore;
        }

        $cores = $em->getRepository(Core::class)->findBy([], ['rowCount' => 'DESC']);

        return $cores[0] ?? null;
    }
}
