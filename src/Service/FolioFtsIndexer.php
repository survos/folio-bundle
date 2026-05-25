<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Vocabulary\ItemField;

final class FolioFtsIndexer
{
    /**
     * @return array{rows:int, bytes:int}
     */
    public function rebuild(string $dbFile): array
    {
        $pdo = $this->connect($dbFile);
        $this->assertFts5($pdo);

        $pdo->exec('DROP TABLE IF EXISTS item_fts');
        $pdo->exec("CREATE VIRTUAL TABLE item_fts USING fts5(body, tokenize='unicode61')");
        $pdo->exec('DROP TABLE IF EXISTS item_vocab');
        $pdo->exec("CREATE VIRTUAL TABLE item_vocab USING fts5vocab('item_fts', 'row')");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_core_dto_type ON item(core_id, dto_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_dto_type ON item(dto_type)');

        $searchableProperties = $this->searchableProperties($pdo);
        $select = $pdo->query('SELECT rowid, local_id, label, dto_type, dto_data, extras FROM item ORDER BY rowid');
        if (!$select instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to read folio rows for FTS indexing.');
        }

        $insert = $pdo->prepare('INSERT INTO item_fts(rowid, body) VALUES (:rowid, :body)');
        $rows = 0;
        $bytes = 0;

        $pdo->beginTransaction();
        try {
            while ($row = $select->fetch(\PDO::FETCH_ASSOC)) {
                $body = $this->searchBody($row, $searchableProperties);
                $insert->execute([
                    'rowid' => (int) $row['rowid'],
                    'body' => $body,
                ]);
                ++$rows;
                $bytes += strlen($body);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $pdo->exec('INSERT INTO item_fts(item_fts) VALUES (\'optimize\')');

        return ['rows' => $rows, 'bytes' => $bytes];
    }

    public function drop(string $dbFile): void
    {
        $pdo = $this->connect($dbFile);
        $pdo->exec('DROP TABLE IF EXISTS item_vocab');
        $pdo->exec('DROP TABLE IF EXISTS item_fts');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $dbFile, string $query, int $limit = 10): array
    {
        $pdo = $this->connect($dbFile);
        $stmt = $pdo->prepare(
            'SELECT d.local_id AS localId, d.label, d.dto_type AS dtoType, d.core_id AS coreId, bm25(item_fts) AS score
             FROM item d
             JOIN item_fts f ON f.rowid = d.rowid
             WHERE item_fts MATCH :query
             ORDER BY score ASC
             LIMIT :limit'
        );
        $stmt->bindValue('query', $this->matchQuery($query));
        $stmt->bindValue('limit', max(1, $limit), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
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

    private function assertFts5(\PDO $pdo): void
    {
        try {
            $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS _folio_fts_probe USING fts5(body)");
            $pdo->exec('DROP TABLE IF EXISTS _folio_fts_probe');
        } catch (\Throwable $e) {
            throw new \RuntimeException('This SQLite build does not support FTS5.', previous: $e);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    /**
     * @param array<string,true> $searchableProperties
     */
    private function searchBody(array $row, array $searchableProperties): string
    {
        $parts = [
            $row['local_id'] ?? null,
            $row['label'] ?? null,
            $row['dto_type'] ?? null,
        ];

        foreach (['dto_data', 'extras'] as $column) {
            $decoded = $this->decodeJson($row[$column] ?? null);
            if (is_array($decoded)) {
                $this->appendScalars($decoded, $parts, $searchableProperties);
            }
        }

        return trim(implode(' ', array_unique(array_filter(
            array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $parts),
            static fn (string $value): bool => $value !== '',
        ))));
    }

    private function decodeJson(mixed $json): mixed
    {
        if (!is_string($json) || trim($json) === '') {
            return null;
        }

        try {
            return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param array<mixed> $data
     * @param list<mixed>  $parts
     */
    /**
     * @param array<string,true> $searchableProperties
     */
    private function appendScalars(array $data, array &$parts, array $searchableProperties): void
    {
        foreach ($data as $key => $value) {
            if ($searchableProperties !== [] && is_string($key) && !isset($searchableProperties[$key])) {
                continue;
            }

            if (is_scalar($value)) {
                $parts[] = $value;
                continue;
            }

            if (is_array($value)) {
                $this->appendScalars($value, $parts, []);
            }
        }
    }

    /**
     * @return array<string,true>
     */
    private function searchableProperties(\PDO $pdo): array
    {
        try {
            $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_property'")->fetchColumn();
            if ($exists !== 'schema_property') {
                return [];
            }

            $rows = $pdo->query('SELECT DISTINCT name FROM schema_property WHERE searchable = 1')->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return [];
        }

        $properties = [];
        foreach ($rows ?: [] as $name) {
            if (is_string($name) && $name !== '') {
                $properties[$name] = true;
            }
        }
        $properties[ItemField::SEARCH_SUMMARY] = true;

        return $properties;
    }

    private function matchQuery(string $query): string
    {
        return str_replace('"', '""', trim($query));
    }
}
