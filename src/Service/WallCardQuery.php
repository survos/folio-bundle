<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\DBAL\Connection;
use Survos\DataContracts\Vocabulary\{ItemField, MuseumVocab};
use Survos\FolioBundle\Api\WallCardField as Field;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** One SQL predicate for counting and paging; hangability is tested before LIMIT. */
final class WallCardQuery
{
    public function fetch(Connection $connection, string $coreId, array $query): array
    {
        $native = $connection->getNativeConnection();
        $register = static function (string $name, callable $fn, int $argc) use ($native): void {
            if ($native instanceof \Pdo\Sqlite) { $native->createFunction($name, $fn, $argc, \Pdo\Sqlite::DETERMINISTIC); }
            elseif ($native instanceof \SQLite3) { $native->createFunction($name, $fn, $argc, SQLITE3_DETERMINISTIC); }
            else { throw new \LogicException('WallCard requires a native SQLite connection.'); }
        };
        $register('wallcard_has_size', static fn ($d, $w, $h) => (int) (WallCardMapper::size($d, $w, $h) !== null), 3);
        $register('wallcard_has_image', static fn ($u) => (int) WallCardMapper::renderable($u), 1);
        $register('wallcard_shuffle', static fn ($id, $seed) => hash('sha256', $seed."\0".$id), 2);
        $value = static fn (string $key): string => "COALESCE(json_extract(i.dto_data, '$.\"$key\"'), json_extract(i.extras, '$.\"$key\"'))";
        $yearValue = $value(ItemField::YEAR);
        $year = "CASE WHEN $yearValue IS NOT NULL THEN CAST($yearValue AS INTEGER) END";
        $columns = $connection->fetchFirstColumn("SELECT name FROM pragma_table_info('item')");
        if (in_array('sort_key', $columns, true)) { $year = "COALESCE(i.sort_key, $year)"; }
        $lat = 'COALESCE('.$value(Field::LATITUDE).', '.$value(ItemField::LATITUDE).')';
        $lon = 'COALESCE('.$value(Field::LONGITUDE).', '.$value(ItemField::LONGITUDE).')';
        $from = 'item i LEFT JOIN page p ON p.id = (SELECT first_page.id FROM page first_page WHERE first_page.row_id = i.id ORDER BY first_page.seq, first_page.id LIMIT 1)';
        $where = ['i.core_id = :core'];
        $params = ['core' => $coreId];
        $page = $this->integer($query, 'page', 1, 1, 1000000);
        $limit = $this->integer($query, 'itemsPerPage', 50, 1, 200);
        $type = $this->string($query, 'type');
        if ($type !== null) { $where[] = 'i.dto_type = :type'; $params['type'] = $type; }
        if (isset($query['year'])) {
            if (!is_array($query['year']) || array_diff(array_keys($query['year']), ['gte', 'lte']) !== []) { throw new BadRequestHttpException('Use year[gte] and year[lte].'); }
            foreach (['gte' => '>=', 'lte' => '<='] as $key => $operator) {
                if (isset($query['year'][$key])) {
                    $params[$key] = $this->integer($query['year'], $key, 0, -10000, 10000);
                    $where[] = "$year $operator CAST(:$key AS INTEGER)";
                }
            }
        }
        foreach ([ItemField::CITY, ItemField::STATE, ItemField::COUNTRY, Field::DONOR, 'tag'] as $facet) {
            if (null !== $filter = $this->string($query, $facet)) {
                $field = $facet === 'tag' ? Field::TAGS : $facet;
                $where[] = "EXISTS (SELECT 1 FROM item_facet f WHERE f.item_rowid = i.rowid AND f.field = :field_$facet AND f.value = :facet_$facet)";
                $params['field_'.$facet] = $field; $params['facet_'.$facet] = $filter;
            }
        }
        if (null !== $q = $this->string($query, 'q')) {
            // Treat input as ordinary text, not an FTS expression language (no syntax errors or operators).
            $terms = preg_split('/\s+/u', trim($q), flags: PREG_SPLIT_NO_EMPTY);
            if ($terms !== []) {
                if (!$connection->fetchOne("SELECT 1 FROM sqlite_master WHERE name='item_fts'")) { throw new BadRequestHttpException('This folio has no FTS index.'); }
                $params['fts'] = implode(' AND ', array_map(static fn (string $term) => '"'.str_replace('"', '""', $term).'"', $terms));
                $where[] = 'i.rowid IN (SELECT rowid FROM item_fts WHERE item_fts MATCH :fts)';
            }
        }
        if (null !== $bbox = $this->string($query, 'bbox')) {
            $bounds = explode(',', $bbox);
            if (count($bounds) !== 4 || !array_all($bounds, static fn ($v) => is_numeric($v) && is_finite((float) $v))) { throw new BadRequestHttpException('bbox must be minLon,minLat,maxLon,maxLat.'); }
            [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $bounds);
            if ($minLon < -180 || $maxLon > 180 || $minLat < -90 || $maxLat > 90 || $minLon > $maxLon || $minLat > $maxLat) { throw new BadRequestHttpException('Invalid bbox bounds.'); }
            $where[] = "$lon BETWEEN CAST(:minLon AS REAL) AND CAST(:maxLon AS REAL) AND $lat BETWEEN CAST(:minLat AS REAL) AND CAST(:maxLat AS REAL)";
            $params += compact('minLon', 'minLat', 'maxLon', 'maxLat');
        }
        foreach (['hasSize', 'hasImage'] as $flag) {
            $enabled = $this->integer($query, $flag, 0, 0, 1);
            if ($enabled) { $where[] = $flag === 'hasSize' ? 'wallcard_has_size('.$value(Field::DIMENSIONS_RAW).', p.width, p.height) = 1' : 'wallcard_has_image(p.url) = 1'; }
        }
        $order = $this->string($query, 'order') ?? 'year';
        $sort = match ($order) {
            'year' => "$year ASC NULLS LAST, i.local_id ASC",
            'label' => 'i.label COLLATE NOCASE ASC NULLS LAST, i.local_id ASC',
            'random' => 'wallcard_shuffle(i.local_id, :seed), i.local_id ASC',
            default => throw new BadRequestHttpException('order must be year, label or random.'),
        };
        $orderParams = [];
        if ($order === 'random') { $orderParams['seed'] = $this->integer($query, 'seed', 0, -2147483648, 2147483647); }
        if (null !== $ids = $this->string($query, 'ids')) {
            $ids = array_values(array_unique(explode(',', $ids)));
            if (count($ids) > 2000 || in_array('', $ids, true)) { throw new BadRequestHttpException('ids must contain 1 to 2000 nonempty local ids.'); }
            $slots = []; $cases = [];
            foreach ($ids as $index => $id) { $slots[] = ':id'.$index; $params['id'.$index] = $id; $cases[] = 'WHEN :id'.$index.' THEN '.$index; }
            $where[] = 'i.local_id IN ('.implode(',', $slots).')';
            $sort = 'CASE i.local_id '.implode(' ', $cases).' END';
            $orderParams = [];
        }
        $predicate = implode(' AND ', $where);
        $total = (int) $connection->fetchOne("SELECT COUNT(*) FROM $from WHERE $predicate", $params);
        $rows = $connection->fetchAllAssociative("SELECT i.local_id, i.label, i.dto_data, i.extras, $year AS card_year, $lat AS card_lat, $lon AS card_lon, p.url AS page_url, p.width AS page_width, p.height AS page_height, p.thumb_hash AS page_thumb_hash, p.color AS page_color FROM $from WHERE $predicate ORDER BY $sort LIMIT $limit OFFSET ".(($page - 1) * $limit), $params + $orderParams);
        return compact('rows', 'total', 'page', 'limit');
    }

    private function string(array $query, string $key): ?string
    {
        if (!array_key_exists($key, $query)) { return null; }
        if (!is_string($query[$key]) || strlen($query[$key]) > 16000) { throw new BadRequestHttpException($key.' must be a string of at most 16000 bytes.'); }
        return $query[$key];
    }

    private function integer(array $query, string $key, int $default, int $min, int $max): int
    {
        if (!array_key_exists($key, $query)) { return $default; }
        $value = filter_var($query[$key], FILTER_VALIDATE_INT);
        if ($value === false || $value < $min || $value > $max) { throw new BadRequestHttpException(sprintf('%s must be an integer between %d and %d.', $key, $min, $max)); }
        return $value;
    }
}
