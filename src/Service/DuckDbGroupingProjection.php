<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Metadata\ContentType;

/**
 * Typed, queryable tables for a newspaper issue's saved text-sheet grouping.
 *
 * The grouping lives in `item.extras` as one JSON document per issue (about 90 KB each). A reader that needs one
 * issue then fetches the whole `extras` column segment, hundreds of megabytes over S3. These tables carry the same
 * data as columns, sorted by issue so DuckDB's min/max statistics prune a lookup to a single small row group:
 *
 *   issue_grouping (issue_id, schema_version, source_hash, algorithm_hash, input_hash, folio_code, revision, marking)
 *   issue_page (issue_id, page_index, column_count, body_font_size, masthead_status, slots, analysis, ...)
 *   issue_group (issue_id, page_index, ord, id, parent_id, type, col, basis, title, article_id, block_ids, roles, evidence)
 *   issue_group_role (issue_id, page_index, group_ord, group_id, ord, role, block_id, start_line, end_line)
 *
 *   issue_extras (issue_id, extras) -- the rest of the issue's extras (including any grouping history), a
 *                                      few KB, sorted by issue
 *
 * A reader assembles the issue's extras from these projections without touching `item.extras`. The JSON in
 * `item.extras` is left untouched, so older readers, the rend and the folio comparator keep working.
 */
final class DuckDbGroupingProjection
{
    /** The extras key written by the periodical grouping service. */
    public const string FIELD = 'textsheetGrouping';
    public const string HISTORY = 'textsheetGroupingHistory';

    private const string STAGE = '_issue_grouping_stage';

    /**
     * One staging line per page of a newspaper issue's grouping; empty for any other row.
     *
     * @param array<string,mixed> $row an `item` row as read from the source folio (after geometry was split off)
     *
     * @return list<array{issue_id:string,page_index:int,meta:?array,page:?array,extras:?array}>
     */
    public static function pages(array $row): array
    {
        if (ContentType::NEWSPAPER !== ($row['dto_type'] ?? null) || !is_string($row['extras'] ?? null)) {
            return [];
        }
        $extras = json_decode($row['extras'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($extras)) {
            return [];
        }
        $issue = (string) $row['local_id'];
        $grouping = $extras[self::FIELD] ?? null;
        // Preserve history in issue_extras: the rendition still restores its geometry paths.
        unset($extras[self::FIELD]);
        // Only the issue record itself (core "doc") has an issue-level extras document.
        $lines = str_ends_with((string) ($row['core_id'] ?? ''), ':doc')
            ? [['issue_id' => $issue, 'page_index' => -1, 'meta' => null, 'page' => null, 'extras' => $extras]] : [];
        if (!is_array($grouping) || !is_array($grouping['pages'] ?? null)) {
            return $lines;
        }
        $meta = $grouping;
        unset($meta['pages']);
        foreach ($grouping['pages'] as $page) {
            $lines[] = ['issue_id' => $issue, 'page_index' => (int) ($page['pageIndex'] ?? 0), 'meta' => $meta, 'page' => $page, 'extras' => null];
        }

        return $lines;
    }

    /** Create the staging table before the first chunk is ingested. */
    public static function ingest(\PDO $db, string $file, bool $first): void
    {
        $select = "SELECT json->>'issue_id' AS issue_id, (json->>'page_index')::INTEGER AS page_index, json->'meta' AS meta, json->'page' AS page, json->'extras' AS extras FROM read_ndjson_objects(".$db->quote($file).')';
        $db->exec(($first ? 'CREATE TABLE '.self::STAGE.' AS ' : 'INSERT INTO '.self::STAGE.' ').$select);
    }

    /** Build the typed tables, sorted by issue, from the staged pages; drops the staging table. */
    public static function materialize(\PDO $db): array
    {
        $db->exec('CREATE TABLE IF NOT EXISTS '.self::STAGE.' (issue_id VARCHAR, page_index INTEGER, meta JSON, page JSON, extras JSON)');
        $db->exec(<<<'SQL'
            CREATE TABLE issue_grouping AS
            SELECT issue_id,
                   any_value((meta->>'$.schemaVersion')::INTEGER) AS schema_version,
                   any_value(meta->>'$.sourceHash') AS source_hash,
                   any_value(meta->>'$.algorithmHash') AS algorithm_hash,
                   any_value(meta->>'$.inputHash') AS input_hash,
                   any_value(meta->>'$.folioCode') AS folio_code,
                   any_value(meta->>'$.revision') AS revision,
                   any_value(meta->>'$.marking') AS marking
            FROM _issue_grouping_stage WHERE json_type(page) = 'OBJECT'
            GROUP BY issue_id
            ORDER BY issue_id
            SQL);
        $db->exec(<<<'SQL'
            CREATE TABLE issue_page AS
            SELECT issue_id, page_index,
                   (page->>'$.analysis.columnCount')::INTEGER AS column_count,
                   (page->>'$.analysis.bodyFontSize')::DOUBLE AS body_font_size,
                   page->>'$.analysis.mastheadStatus' AS masthead_status,
                   json_extract(page, '$.analysis') AS analysis,
                   json_extract(page, '$.analysis.slots') AS slots,
                   json_extract(page, '$.analysis.displayFontSizes') AS display_font_sizes,
                   json_extract(page, '$.unassignedBlockIds') AS unassigned_block_ids,
                   json_extract(page, '$.programBlockIds') AS program_block_ids,
                   json_extract(page, '$.mastheadBlockIds') AS masthead_block_ids
            FROM _issue_grouping_stage WHERE json_type(page) = 'OBJECT'
            ORDER BY issue_id, page_index
            SQL);
        // Groups keep their array order: index into the page's list rather than relying on window ordering.
        $db->exec(<<<'SQL'
            CREATE TABLE issue_group AS
            SELECT issue_id, page_index, o.i + 1 AS ord,
                   g->>'$.id' AS id, g->>'$.parentId' AS parent_id, g->>'$.type' AS type,
                   (g->>'$.column')::INTEGER AS col, g->>'$.basis' AS basis, g->>'$.title' AS title,
                   g->>'$.articleId' AS article_id, (g->'$.blockIds')::VARCHAR[] AS block_ids,
                   json_extract(g, '$.roles') AS roles, json_extract(g, '$.evidence') AS evidence
            FROM (SELECT *, json_array_length(json_extract(page, '$.groups'))::BIGINT AS n FROM _issue_grouping_stage WHERE json_type(page) = 'OBJECT') s,
                 unnest(range(s.n)) AS o(i),
                 LATERAL (SELECT json_extract(s.page, '$.groups[' || o.i || ']') AS g)
            ORDER BY issue_id, page_index, ord
            SQL);
        $db->exec("CREATE TABLE issue_extras AS SELECT issue_id, extras FROM _issue_grouping_stage WHERE json_type(extras) = 'OBJECT' ORDER BY issue_id");
        $db->exec(<<<'SQL'
            CREATE TABLE issue_group_role AS
            SELECT g.issue_id, g.page_index, g.ord AS group_ord, g.id AS group_id,
                   r.i AS ord, role->>'$.role' AS role, role->>'$.id' AS block_id,
                   (role->>'$.start')::INTEGER AS start_line, (role->>'$.end')::INTEGER AS end_line
            FROM issue_group g, unnest(range(json_array_length(g.roles)::BIGINT)) AS r(i),
                 LATERAL (SELECT json_extract(g.roles, '$[' || r.i || ']') AS role)
            ORDER BY g.issue_id, g.page_index, g.ord, r.i
            SQL);
        $db->exec('DROP TABLE '.self::STAGE);

        return [
            'main.issue_extras' => (int) $db->query('SELECT count(*) FROM issue_extras')->fetchColumn(),
            'main.issue_grouping' => (int) $db->query('SELECT count(*) FROM issue_grouping')->fetchColumn(),
            'main.issue_page' => (int) $db->query('SELECT count(*) FROM issue_page')->fetchColumn(),
            'main.issue_group' => (int) $db->query('SELECT count(*) FROM issue_group')->fetchColumn(),
            'main.issue_group_role' => (int) $db->query('SELECT count(*) FROM issue_group_role')->fetchColumn(),
        ];
    }
}
