<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * Resolves a row's claims (predicate/value/source/confidence/agent) straight from the folio
 * sqlite `claim` table. Extracted from FolioController::rowShow() so FolioAiController's
 * task-runner page can show the same claims (including ones just persisted by an AI task run)
 * without duplicating this query.
 */
final class RowClaimsResolver
{
    /** @return list<array<string,mixed>> */
    public function resolve(Connection $conn, string $itemId): array
    {
        if (!$this->tableExists($conn, 'claim')) {
            return [];
        }

        // Folios built before claim.meta existed have no such column; select NULL instead.
        $hasMeta = in_array('meta', array_map(
            static fn (\Doctrine\DBAL\Schema\Column $c): string => strtolower($c->getName()),
            $conn->createSchemaManager()->introspectTableColumnsByUnquotedName('claim'),
        ), true);
        $metaColumn = $hasMeta ? 'meta' : 'NULL AS meta';
        $rows = $conn->executeQuery(
            'SELECT predicate, value, source, confidence, agent, claimed_at, run_id, ' . $metaColumn . ' FROM claim WHERE item_id = ? ORDER BY source, predicate',
            [$itemId],
        )->fetchAllAssociative();

        // meta is the claim's provenance JSON; its `basis` is why the claim was made (e.g. the
        // evidence behind each fortepan curation score) and the claims panel shows it.
        return array_map(static function (array $row): array {
            $meta = is_string($row['meta'] ?? null) ? json_decode($row['meta'], true) : null;
            $row['meta'] = is_array($meta) ? $meta : null;

            return $row;
        }, $rows);
    }

    /**
     * @param list<array<string,mixed>> $claims
     * @return array<string,string> task/source key => ClaimRun id
     */
    public function aiTaskRuns(array $claims): array
    {
        $runs = [];
        foreach ($claims as $claim) {
            $source = $claim['source'] ?? null;
            $runId = $claim['run_id'] ?? null;
            if (!is_string($source) || $source === '' || !is_string($runId) || $runId === '') {
                continue;
            }

            $task = str_starts_with($source, 'ai:') ? substr($source, 3) : $source;
            $runs[$task] ??= $runId;
        }

        return $runs;
    }

    private function tableExists(Connection $conn, string $table): bool
    {
        try {
            return $conn->createSchemaManager()->tablesExist([$table]);
        } catch (\Throwable) {
            return false;
        }
    }
}
