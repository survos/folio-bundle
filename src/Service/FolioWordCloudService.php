<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Model\FolioContext;

final readonly class FolioWordCloudService
{
    public function __construct(
        private FolioQueryAnalyzer $queryAnalyzer,
    ) {}

    /**
     * @return list<array{text: string, value: float, count: int, docs: int}>
     */
    public function cloud(FolioContext $ctx, int $limit = 50): array
    {
        $pdo = $this->connect($ctx->path);
        if (!$this->hasVocab($pdo)) {
            return [];
        }

        $totalDocs = max(1, (int) $pdo->query('SELECT COUNT(*) FROM item')->fetchColumn());
        $maxDoc = max(3, (int) floor($totalDocs * 0.35));
        $stmt = $pdo->prepare(
            'SELECT term, cnt, doc
             FROM item_vocab
             WHERE doc >= 2 AND doc <= :maxDoc AND length(term) > 3
             ORDER BY cnt DESC
             LIMIT :candidateLimit'
        );
        $stmt->bindValue('maxDoc', $maxDoc, \PDO::PARAM_INT);
        $stmt->bindValue('candidateLimit', max($limit * 8, 100), \PDO::PARAM_INT);
        $stmt->execute();

        $cloud = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $term = is_string($row['term'] ?? null) ? $row['term'] : '';
            if ($term === '' || $this->queryAnalyzer->isStopWord($term)) {
                continue;
            }

            $count = (int) $row['cnt'];
            $docs = max(1, (int) $row['doc']);
            $cloud[] = [
                'text' => $term,
                'value' => $count * log($totalDocs / $docs),
                'count' => $count,
                'docs' => $docs,
            ];
        }

        usort($cloud, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return array_slice($cloud, 0, $limit);
    }

    private function connect(string $dbFile): \PDO
    {
        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function hasVocab(\PDO $pdo): bool
    {
        return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'item_vocab'")->fetchColumn() === 'item_vocab';
    }
}
