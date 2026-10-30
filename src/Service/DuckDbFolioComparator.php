<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/** Full-value comparison, including duplicates, nulls, JSON text and DTO projections. */
final class DuckDbFolioComparator
{
    public function compare(string $folio, string $duck): array
    {
        $sqlite = new \PDO('sqlite:file:'.$folio.'?mode=ro');
        $sqlite->beginTransaction();
        $db = (new DuckDbFolioReader())->open($duck);
        $counts = [];
        foreach (DuckDbFolioExporter::tables($sqlite) as $table) {
            $quoted = DuckDbFolioExporter::identifier($table);
            $columns = $sqlite->query('PRAGMA table_xinfo('.$quoted.')')->fetchAll(\PDO::FETCH_ASSOC);
            $keys = array_filter($columns, static fn (array $c): bool => $c['pk'] > 0);
            usort($keys, static fn (array $a, array $b): int => $a['pk'] <=> $b['pk']);
            $keys = array_map(static fn (array $c): string => DuckDbFolioExporter::identifier($c['name']), $keys ?: $columns);
            $order = implode(', ', $keys);
            $sql = 'SELECT * FROM '.$quoted.' ORDER BY '.$order;
            $left = $sqlite->query($sql);
            $right = $db->query($sql);
            $count = 0;
            while (true) {
                $expected = $left->fetch(\PDO::FETCH_ASSOC);
                $actual = $right->fetch(\PDO::FETCH_ASSOC);
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
            do {
                $rows = $sqlite->query('SELECT id, core_id, dto_data FROM item WHERE dto_type = '.$sqlite->quote($type).' ORDER BY id LIMIT 1000 OFFSET '.$count)->fetchAll(\PDO::FETCH_ASSOC);
                $actual = $db->query('SELECT to_json(t)::VARCHAR FROM dto.'.DuckDbFolioExporter::identifier($type).' t ORDER BY "_itemId" LIMIT 1000 OFFSET '.$count)->fetchAll(\PDO::FETCH_COLUMN);
                if (count($rows) !== count($actual)) {
                    throw new \RuntimeException('DTO projection count mismatch: '.$type);
                }
                foreach ($rows as $i => $row) {
                    $data = json_decode($row['dto_data'], true, flags: JSON_THROW_ON_ERROR);
                    $wanted = ['_itemId' => $row['id'], '_coreId' => $row['core_id']];
                    foreach ($shape as $name => $sqlType) {
                        $wanted[$name] = $data[$name] ?? null;
                    }
                    $got = json_decode($actual[$i], true, flags: JSON_THROW_ON_ERROR);
                    // JSON does not distinguish integral double encodings (1 versus 1.0).
                    if (self::canonical($wanted) !== self::canonical($got)) {
                        $fields = array_keys(array_filter($wanted, static fn ($value, $key): bool => self::canonical($value) !== self::canonical($got[$key] ?? null), ARRAY_FILTER_USE_BOTH));
                        throw new \RuntimeException('DTO value mismatch: '.$row['id'].' fields '.implode(', ', $fields));
                    }
                }
                $count += count($rows);
            } while (1000 === count($rows));
            $projections[$type] = $count;
        }
        $sqlite->commit();

        return ['tables' => $counts, 'dtoProjections' => $projections, 'matched' => true];
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
