<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Model\FolioSummary;

final class FolioSummaryService
{
    public function summarize(string $dbFile): FolioSummary
    {
        if (!is_file($dbFile)) {
            return new FolioSummary(null);
        }

        try {
            $pdo = new \PDO('sqlite:' . $dbFile);
            $rowCount = (int) $pdo->query('SELECT COUNT(*) FROM item')->fetchColumn();
            $cores = $pdo->query('SELECT code, label, row_count AS rowCount FROM core ORDER BY code')
                ->fetchAll(\PDO::FETCH_ASSOC);

            $coreCounts = [];
            foreach ($cores as $core) {
                $coreCounts[(string) $core['code']] = (int) $core['rowCount'];
            }

            $columns = array_map(
                static fn(array $row): string => (string) $row['name'],
                $pdo->query('PRAGMA table_info(item)')->fetchAll(\PDO::FETCH_ASSOC)
            );
            $dtoColumn = in_array('dto_type', $columns, true)
                ? 'dto_type'
                : (in_array('dto_class', $columns, true) ? 'dto_class' : null);

            $dtoCounts = [];
            if ($dtoColumn !== null) {
                $dtoRows = $pdo->query(sprintf(
                    "SELECT COALESCE(NULLIF(%s, ''), '(none)') AS dtoType, COUNT(*) AS cnt FROM item GROUP BY dtoType ORDER BY cnt DESC, dtoType",
                    $dtoColumn,
                ))->fetchAll(\PDO::FETCH_ASSOC);

                foreach ($dtoRows as $row) {
                    $dtoCounts[$this->shortDtoType((string) $row['dtoType'])] = (int) $row['cnt'];
                }
            }

            return new FolioSummary(
                rowCount: $rowCount,
                cores: array_map(static fn(array $row): array => [
                    'code' => (string) $row['code'],
                    'label' => $row['label'] !== null ? (string) $row['label'] : null,
                    'rowCount' => (int) $row['rowCount'],
                ], $cores ?: []),
                coreCounts: $coreCounts,
                dtoCounts: $dtoCounts,
            );
        } catch (\Throwable) {
            return new FolioSummary(null);
        }
    }

    private function shortDtoType(string $type): string
    {
        $short = substr(strrchr('\\' . $type, '\\'), 1);
        $short = preg_replace('/Dto$/', '', $short) ?? $short;

        return lcfirst($short);
    }
}
