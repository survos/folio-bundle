<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/** Full-value comparison, including duplicates, nulls, JSON text and DTO projections. */
final class DuckDbFolioComparator
{
    /** @param callable(array):\PDO|null $openRend Opens the pinned rendition for full geometry verification. */
    public function compare(string $folio, string $duck, ?callable $openRend = null): array
    {
        $sqlite = new \PDO('sqlite:file:'.$folio.'?mode=ro');
        $sqlite->beginTransaction();
        $db = (new DuckDbFolioReader())->open($duck);
        $split = (bool) $db->query("SELECT count(*) FROM information_schema.tables WHERE table_name = '_renditions'")->fetchColumn();
        if ($split && null === $openRend) {
            throw new \RuntimeException('Full newspaper comparison requires the pinned renditions.');
        }
        $refs = $split ? $db->query('SELECT * FROM _renditions')->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC) : [];
        $activeIssue = null;
        $patches = [];
        $restore = static function (string $table, array $row, array $expected) use ($sqlite, $refs, $openRend, &$activeIssue, &$patches): array {
            $item = $expected;
            if ('page' === $table) {
                $stmt = $sqlite->prepare('SELECT core_id, local_id, extras FROM item WHERE id = ?');
                $stmt->execute([$expected['row_id']]);
                $item = $stmt->fetch(\PDO::FETCH_ASSOC);
            }
            $issue = str_ends_with($item['core_id'], ':doc') ? $item['local_id'] : json_decode($item['extras'], true, flags: JSON_THROW_ON_ERROR)['issueId'];
            if ($activeIssue !== $issue) {
                $ref = $refs[$issue] ?? throw new \RuntimeException('Missing issue rendition.');
                $rend = $openRend(['issue_id' => $issue] + $ref);
                $meta = $rend->query('SELECT k,v FROM meta')->fetchAll(\PDO::FETCH_KEY_PAIR);
                if (($meta['buildId'] ?? null) !== $ref['build_id'] || DuckDbRendSchema::PAPER_VERSION !== (int) ($meta['layout'] ?? 0)) {
                    throw new \RuntimeException('Rend identity mismatch.');
                }
                foreach (['item', 'page'] as $kind) {
                    $statement = $rend->prepare('SELECT id,patch FROM '.$kind.'_geometry WHERE issue = ?');
                    $statement->execute([$issue]);
                    $patches[$kind] = $statement->fetchAll(\PDO::FETCH_KEY_PAIR);
                }
                $activeIssue = $issue;
            }
            $patch = $patches[$table][$row['id']] ?? throw new \RuntimeException('Missing geometry row.');

            return NewspaperGeometry::restoreRow($table, $row, json_decode($patch, true, flags: JSON_THROW_ON_ERROR));
        };
        $counts = [];
        foreach (DuckDbFolioExporter::tables($sqlite) as $table) {
            $quoted = DuckDbFolioExporter::identifier($table);
            $columns = $sqlite->query('PRAGMA table_xinfo('.$quoted.')')->fetchAll(\PDO::FETCH_ASSOC);
            $keys = array_filter($columns, static fn (array $c): bool => $c['pk'] > 0);
            usort($keys, static fn (array $a, array $b): int => $a['pk'] <=> $b['pk']);
            $keys = array_map(static fn (array $c): string => DuckDbFolioExporter::identifier($c['name']), $keys ?: $columns);
            $order = implode(', ', $keys);
            // Compare source columns; export-only typed projections are verified separately.
            $sql = 'SELECT '.implode(', ', array_map(static fn (array $c): string => DuckDbFolioExporter::identifier($c['name']), $columns)).' FROM '.$quoted.' ORDER BY '.$order;
            $left = $sqlite->query($sql);
            $right = $db->query($sql);
            $count = 0;
            while (true) {
                $expected = $left->fetch(\PDO::FETCH_ASSOC);
                $actual = $right->fetch(\PDO::FETCH_ASSOC);
                if ($split && false !== $expected && false !== $actual && in_array($table, ['item', 'page'], true)) {
                    $actual = $restore($table, $actual, $expected);
                    // Separation may change object key order; compare the full JSON values.
                    foreach ('item' === $table ? ['dto_data', 'extras', 'raw'] : ['layout', 'ledger', 'dialogue'] as $field) {
                        if (isset($expected[$field])) {
                            $expected[$field] = self::canonical(json_decode($expected[$field], true, flags: JSON_THROW_ON_ERROR));
                            $actual[$field] = self::canonical(json_decode($actual[$field], true, flags: JSON_THROW_ON_ERROR));
                        }
                    }
                }
                if ($expected !== $actual) {
                    throw new \RuntimeException('Content mismatch in '.$table.' at row '.$count);
                }
                if (false === $expected) {
                    break;
                }
                ++$count;
            }
            $left = $right = null;
            $counts[$table] = $count;
        }
        $projections = [];
        foreach ($db->query('SELECT dto_type, structure::VARCHAR AS structure FROM _dto_schema')->fetchAll(\PDO::FETCH_ASSOC) as $contract) {
            $shape = json_decode($contract['structure'], true, flags: JSON_THROW_ON_ERROR);
            $type = $contract['dto_type'];
            $count = 0;
            $sourceRows = $sqlite->query('SELECT id, core_id, dto_data FROM item WHERE dto_type = '.$sqlite->quote($type).' ORDER BY id');
            $targetRows = $db->query('SELECT to_json(t)::VARCHAR FROM dto.'.DuckDbFolioExporter::identifier($type).' t ORDER BY "_itemId"');
            while (false !== ($row = $sourceRows->fetch(\PDO::FETCH_ASSOC))) {
                $actual = $targetRows->fetchColumn();
                if (false === $actual) {
                    throw new \RuntimeException('DTO projection count mismatch: '.$type);
                }
                if ($split) {
                    [$row] = NewspaperGeometry::splitRow('item', $row);
                }
                $data = json_decode($row['dto_data'], true, flags: JSON_THROW_ON_ERROR);
                $wanted = ['_itemId' => $row['id'], '_coreId' => $row['core_id']];
                foreach ($shape as $name => $sqlType) {
                    $value = $data[$name] ?? null;
                    // Legacy serializers sometimes encoded a declared list twice.
                    if (is_array($sqlType) && is_string($value)) {
                        $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                    }
                    // VARCHAR projections preserve the value, not JSON's numeric type.
                    if ('VARCHAR' === $sqlType && (is_int($value) || is_float($value))) {
                        $value = (string) $value;
                    }
                    $wanted[$name] = $value;
                }
                $got = json_decode($actual, true, flags: JSON_THROW_ON_ERROR);
                // JSON does not distinguish integral double encodings (1 versus 1.0).
                if (self::canonical($wanted) !== self::canonical($got)) {
                    $fields = array_keys(array_filter($wanted, static fn ($value, $key): bool => self::canonical($value) !== self::canonical($got[$key] ?? null), ARRAY_FILTER_USE_BOTH));
                    throw new \RuntimeException('DTO value mismatch: '.$row['id'].' fields '.implode(', ', $fields));
                }

                ++$count;
            }
            if (false !== $targetRows->fetchColumn()) {
                throw new \RuntimeException('DTO projection count mismatch: '.$type);
            }
            $sourceRows = $targetRows = null;
            $projections[$type] = $count;
        }
        $sqlite->commit();

        return ['tables' => $counts, 'dtoProjections' => $projections, 'matched' => true, 'renditionsCompared' => count($refs)];
    }

    private static function canonical(mixed $value): string
    {
        if (is_array($value) && !array_is_list($value)) {
            ksort($value);
        }
        if (is_array($value)) {
            $value = array_map(static fn ($v) => json_decode(self::canonical($v), true, flags: JSON_THROW_ON_ERROR), $value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
