<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Model\FolioChatHit;
use Survos\FolioBundle\Model\FolioContext;

final class FolioRetriever
{
    public function __construct(
        private readonly FolioQueryAnalyzer $queryAnalyzer,
    ) {}

    /**
     * @return list<FolioChatHit>
     */
    public function retrieve(FolioContext $ctx, string $question, int $limit = 12, ?string $coreCode = null, ?string $dtoType = null): array
    {
        $matchQuery = $this->matchQuery($question);
        if ($matchQuery === '') {
            return [];
        }

        $pdo = $this->connect($ctx->path);
        if (!$this->hasFtsIndex($pdo)) {
            throw new \RuntimeException(sprintf('Folio %s has no item_fts index. Run folio:fts:rebuild %s.', $ctx->folioCode, $ctx->folioCode));
        }

        $where = ['item_fts MATCH :query'];
        $params = ['query' => $matchQuery];
        if ($coreCode !== null && $coreCode !== '') {
            $where[] = 'd.core_id = :coreId';
            $params['coreId'] = sprintf('%s:%s', $ctx->folioCode, $coreCode);
        }
        if ($dtoType !== null && $dtoType !== '') {
            $where[] = 'd.dto_type = :dtoType';
            $params['dtoType'] = $dtoType;
        }

        $sql = sprintf(
            "SELECT d.local_id AS localId,
                    d.label,
                    d.dto_type AS dtoType,
                    d.core_id AS coreId,
                    d.dto_data AS dtoData,
                    d.extras AS extras,
                    bm25(item_fts) AS score,
                    snippet(item_fts, 0, '[[[', ']]]', '...', 32) AS snippet
             FROM item d
             JOIN item_fts f ON f.rowid = d.rowid
             WHERE %s
             ORDER BY score ASC
             LIMIT :limit",
            implode(' AND ', $where),
        );

        $stmt = $pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();

        $hits = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            [$provider, $dataset, $rowCoreCode] = $this->splitCoreId((string) $row['coreId']);
            $hits[] = new FolioChatHit(
                provider: $provider,
                dataset: $dataset,
                coreCode: $rowCoreCode,
                localId: (string) $row['localId'],
                label: is_string($row['label'] ?? null) && $row['label'] !== '' ? $row['label'] : null,
                dtoType: is_string($row['dtoType'] ?? null) && $row['dtoType'] !== '' ? $row['dtoType'] : null,
                score: (float) $row['score'],
                snippet: is_string($row['snippet'] ?? null) ? $row['snippet'] : '',
                dtoData: $this->decodeJson($row['dtoData'] ?? null),
                extras: $this->decodeJson($row['extras'] ?? null),
            );
        }

        return $hits;
    }

    private function connect(string $dbFile): \PDO
    {
        if (!is_file($dbFile)) {
            throw new \RuntimeException(sprintf('Folio database not found: %s', $dbFile));
        }

        $pdo = new \PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    private function hasFtsIndex(\PDO $pdo): bool
    {
        return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'item_fts'")->fetchColumn() === 'item_fts';
    }

    /**
     * Convert a natural-language question into conservative FTS5 syntax.
     */
    private function matchQuery(string $question): string
    {
        $terms = $this->queryAnalyzer->terms($question);

        return implode(' OR ', array_map(static fn (string $term): string => $term . '*', array_slice($terms, 0, 12)));
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function splitCoreId(string $coreId): array
    {
        [$folioCode, $coreCode] = array_pad(explode(':', $coreId, 2), 2, 'obj');
        [$provider, $dataset] = array_pad(explode('/', $folioCode, 2), 2, '');

        return [$provider, $dataset, $coreCode];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
