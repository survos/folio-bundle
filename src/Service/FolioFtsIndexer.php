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
        // Porter stemmer over unicode61: natural-language questions use inflected/plural words
        // ("documents", "songs") that must match the singular/root forms stored in the rows
        // ("document", "song"). Without stemming, prefix queries like "documents*" miss "document"
        // entirely, so chat retrieval returned nothing. Porter stems both the index and the query.
        $pdo->exec("CREATE VIRTUAL TABLE item_fts USING fts5(body, tokenize='porter unicode61')");
        $pdo->exec('DROP TABLE IF EXISTS item_vocab');
        $pdo->exec("CREATE VIRTUAL TABLE item_vocab USING fts5vocab('item_fts', 'row')");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_core_dto_type ON item(core_id, dto_type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_dto_type ON item(dto_type)');
        $this->createBrowseIndexes($pdo);

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
        $this->rebuildFacetCounts($pdo);

        return ['rows' => $rows, 'bytes' => $bytes];
    }

    public function drop(string $dbFile): void
    {
        $pdo = $this->connect($dbFile);
        $pdo->exec('DROP TABLE IF EXISTS item_vocab');
        $pdo->exec('DROP TABLE IF EXISTS item_fts');
    }

    private function createBrowseIndexes(\PDO $pdo): void
    {
        foreach (['creator', 'date', 'contentType', 'language'] as $field) {
            $name = sprintf('idx_item_json_%s', strtolower(preg_replace('/[^A-Za-z0-9_]+/', '_', $field) ?? $field));
            $path = '$.' . str_replace("'", "''", $field);
            $pdo->exec(sprintf(
                "CREATE INDEX IF NOT EXISTS %s ON item(json_extract(dto_data, '%s'))",
                $name,
                $path,
            ));
        }
    }

    private function rebuildFacetCounts(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS item_facet_count');
        $pdo->exec('DROP TABLE IF EXISTS item_facet');
        $pdo->exec('CREATE TABLE item_facet (item_rowid INTEGER NOT NULL, field TEXT NOT NULL, value TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_facet_field_value_rowid ON item_facet(field, value, item_rowid)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_facet_rowid_field ON item_facet(item_rowid, field)');
        // Partitioned by core: core='' is the all-cores aggregate (used when no core is selected);
        // core='<coreCode>' is the per-core aggregate. A core-scoped search page (the common case)
        // then reads facet counts straight from this table instead of a per-facet JOIN+GROUP over
        // item_facet — the global '' row would be wrong for a single core, which is why faceting on
        // a core page previously fell back to the slow live aggregation. PK + index lead with core.
        $pdo->exec('CREATE TABLE item_facet_count (core TEXT NOT NULL, field TEXT NOT NULL, value TEXT NOT NULL, total INTEGER NOT NULL, PRIMARY KEY (core, field, value))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_facet_count_core_field_total ON item_facet_count(core, field, total DESC)');

        $fields = $this->facetFields($pdo);
        if ($fields === []) {
            return;
        }

        $counts = [];
        $select = $pdo->query('SELECT rowid, core_id, dto_type, dto_data FROM item ORDER BY rowid');
        if (!$select instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to read folio rows for facet counts.');
        }

        $insertFacet = $pdo->prepare('INSERT INTO item_facet(item_rowid, field, value) VALUES (:itemRowid, :field, :value)');
        $insertCount = $pdo->prepare('INSERT INTO item_facet_count(core, field, value, total) VALUES (:core, :field, :value, :total)');

        $pdo->beginTransaction();
        try {
            while ($row = $select->fetch(\PDO::FETCH_ASSOC)) {
                $data = $this->decodeJson($row['dto_data'] ?? null);
                $itemRowid = (int) $row['rowid'];
                // Reuse the 'core' facet extraction (core code from core_id) to key per-core counts.
                $coreCode = $this->rowFacetValues('core', $row, [])[0] ?? '';
                foreach ($fields as $field) {
                    foreach ($this->rowFacetValues($field, $row, is_array($data) ? $data : []) as $value) {
                        $insertFacet->execute([
                            'itemRowid' => $itemRowid,
                            'field' => $field,
                            'value' => $value,
                        ]);
                        // Tally into the all-cores aggregate and (when known) the row's own core.
                        $counts[''][$field][$value] = ($counts[''][$field][$value] ?? 0) + 1;
                        if ($coreCode !== '') {
                            $counts[$coreCode][$field][$value] = ($counts[$coreCode][$field][$value] ?? 0) + 1;
                        }
                    }
                }
            }

            foreach ($counts as $core => $fieldValues) {
                foreach ($fieldValues as $field => $values) {
                    foreach ($values as $value => $total) {
                        $insertCount->execute([
                            'core' => $core,
                            'field' => $field,
                            'value' => $value,
                            'total' => $total,
                        ]);
                    }
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return list<string>
     */
    private function facetFields(\PDO $pdo): array
    {
        $fields = ['core', 'dtoType', 'creator', 'date', 'contentType', 'language'];

        try {
            $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'schema_property'")->fetchColumn();
            if ($exists === 'schema_property') {
                foreach ($pdo->query('SELECT DISTINCT name FROM schema_property WHERE visible = 1 AND (facet = 1 OR filterable = 1)')->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $name) {
                    if (is_string($name) && $name !== '') {
                        $fields[] = $name;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function rowFacetValues(string $field, array $row, array $data): array
    {
        if ($field === 'core') {
            $coreId = is_string($row['core_id'] ?? null) ? $row['core_id'] : '';
            $value = str_contains($coreId, ':') ? substr($coreId, strrpos($coreId, ':') + 1) : $coreId;

            return $this->validFacetValue($value) ? [$value] : [];
        }

        if ($field === 'dtoType') {
            $value = is_scalar($row['dto_type'] ?? null) ? trim((string) $row['dto_type']) : '';

            return $this->validFacetValue($value) ? [$value] : [];
        }

        return array_key_exists($field, $data) ? $this->facetValues($data[$field]) : [];
    }

    /**
     * @return list<string>
     */
    private function facetValues(mixed $value): array
    {
        if (is_array($value)) {
            $values = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $values[] = trim((string) $item);
                }
            }

            return array_values(array_unique(array_filter($values, $this->validFacetValue(...))));
        }

        if (!is_scalar($value)) {
            return [];
        }

        $value = trim((string) $value);

        return $this->validFacetValue($value) ? [$value] : [];
    }

    private function validFacetValue(string $value): bool
    {
        return $value !== '' && strlen($value) <= 512;
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
