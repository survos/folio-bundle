<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Metadata\ContentType;
use Survos\DataContracts\Vocabulary\ItemField;
use Survos\DataContracts\Vocabulary\PeriodicalVocabulary as V;

/**
 * Typed, queryable tables for a newspaper issue's text-sheet grouping, built from the folio's own records.
 *
 * A grouping is stored as rows and links: one row per group in the "group" core (IssueGroupDto), linked to its
 * issue (group_in_issue, ordered), to the article it was published as (group_article) and to a parent group
 * (group_parent); the grouping's identity is typed fields on the issue row. A reader that wants one issue's
 * grouping should not scan `item` and `link` for it, so the export projects those records into tables sorted by
 * issue, where DuckDB's min/max statistics prune a lookup to a single small row group:
 *
 *   issue_grouping (issue_id, schema_version, source_hash, algorithm_hash, input_hash, folio_code, revision, marking)
 *   issue_page (issue_id, page_index, column_count, body_font_size, masthead_status, analysis, slots, ...)
 *   issue_group (issue_id, page_index, ord, id, parent_id, type, col, basis, title, article_id, block_ids, roles, evidence)
 *   issue_group_role (issue_id, page_index, group_ord, group_id, ord, role, block_id, start_line, end_line)
 *
 * These are a read model: nothing here reads `extras`, and the rows and links stay the record of truth.
 */
final class DuckDbGroupingProjection
{
    /** Whether the exported folio has group rows to project. */
    public static function hasGroups(\PDO $db): bool
    {
        return (bool) $db->query('SELECT count(*) FROM (SELECT 1 FROM item WHERE dto_type = '.$db->quote(ContentType::ISSUE_GROUP).' LIMIT 1)')->fetchColumn();
    }

    /**
     * Build the tables, sorted by issue, from the exported `item` and `link` tables.
     *
     * @return array<string, int> rows per table
     */
    public static function materialize(\PDO $db): array
    {
        $type = static fn (string $code, string $right): string => $db->quote('%:'.V::GROUP_CORE.':'.$code.':'.$right);
        $field = static fn (string $name): string => "json_extract_string(i.dto_data, '$.".$name."')";
        $has = static fn (string $table, string $column): bool => (bool) $db->query('SELECT count(*) FROM information_schema.columns WHERE table_schema = \'main\' AND table_name = '.$db->quote($table).' AND column_name = '.$db->quote($column))->fetchColumn();
        // Links without a type column cannot name a group relation, so such a folio has no groups to project.
        $typed = $has('link', 'link_type_id');
        $linkType = static fn (string $alias, string $code, string $right): string => $typed ? $alias.'.link_type_id LIKE '.$type($code, $right) : 'FALSE';
        $label = $has('item', 'label') ? 'i.label' : 'NULL::VARCHAR';

        $db->exec('CREATE TABLE issue_grouping AS
            SELECT i.local_id AS issue_id,
                   '.$field(ItemField::GROUPING_SCHEMA_VERSION).'::INTEGER AS schema_version,
                   '.$field(ItemField::GROUPING_SOURCE_HASH).' AS source_hash,
                   '.$field(ItemField::GROUPING_ALGORITHM_HASH).' AS algorithm_hash,
                   '.$field(ItemField::GROUPING_INPUT_HASH).' AS input_hash,
                   split_part(i.core_id, \':\', 1) AS folio_code,
                   '.$field(ItemField::GROUPING_REVISION).' AS revision,
                   '.$field(ItemField::GROUPING_MARKING).' AS marking
            FROM item i
            WHERE i.core_id LIKE '.$db->quote('%:'.V::ISSUE_CORE).' AND '.$field(ItemField::GROUPING_REVISION).' IS NOT NULL
            ORDER BY issue_id');

        // Every group with its issue and its order in it; a group's local id is "<issue>#<group id>".
        $db->exec('CREATE TEMP TABLE _issue_group_rows AS
            SELECT l.right_id AS issue_id, l.ordinal, i.local_id, '.$label.' AS label, i.dto_data::JSON AS data,
                   substr(i.local_id, length(l.right_id) + 2) AS id
            FROM link l JOIN item i ON i.local_id = l.left_id AND i.dto_type = '.$db->quote(ContentType::ISSUE_GROUP).'
            WHERE l.left_core = '.$db->quote(V::GROUP_CORE).' AND l.right_core = '.$db->quote(V::ISSUE_CORE).'
              AND '.$linkType('l', V::GROUP_IN_ISSUE, V::ISSUE_CORE));

        $db->exec('CREATE TABLE issue_page AS
            SELECT issue_id, (data->>\'$.pageIndex\')::INTEGER AS page_index,
                   (data->>\'$.analysis.columnCount\')::INTEGER AS column_count,
                   (data->>\'$.analysis.bodyFontSize\')::DOUBLE AS body_font_size,
                   data->>\'$.analysis.mastheadStatus\' AS masthead_status,
                   json_extract(data, \'$.analysis\') AS analysis,
                   json_extract(data, \'$.analysis.slots\') AS slots,
                   json_extract(data, \'$.analysis.displayFontSizes\') AS display_font_sizes,
                   coalesce(json_extract(data, \'$.unassignedBlockIds\'), \'[]\'::JSON) AS unassigned_block_ids,
                   coalesce(json_extract(data, \'$.programBlockIds\'), \'[]\'::JSON) AS program_block_ids,
                   coalesce(json_extract(data, \'$.mastheadBlockIds\'), \'[]\'::JSON) AS masthead_block_ids
            FROM _issue_group_rows WHERE data->>\'$.groupType\' = '.$db->quote(V::GROUP_TYPE_PAGE).'
            ORDER BY issue_id, page_index');

        // ord is the group\'s place on its page; the links order groups across the whole issue.
        $db->exec('CREATE TABLE issue_group AS
            SELECT g.issue_id, (g.data->>\'$.pageIndex\')::INTEGER AS page_index,
                   row_number() OVER (PARTITION BY g.issue_id, (g.data->>\'$.pageIndex\')::INTEGER ORDER BY g.ordinal, g.local_id) AS ord,
                   g.id,
                   -- The parent is another group when a link says so; otherwise it is the column the group sits in.
                   coalesce(substr(p.right_id, length(g.issue_id) + 2), \'column-\' || (g.data->>\'$.column\')) AS parent_id,
                   g.data->>\'$.groupType\' AS type, (g.data->>\'$.column\')::INTEGER AS col, g.data->>\'$.basis\' AS basis,
                   g.label AS title, a.right_id AS article_id,
                   coalesce((g.data->\'$.blockIds\')::VARCHAR[], []::VARCHAR[]) AS block_ids,
                   coalesce(json_extract(g.data, \'$.roles\'), \'[]\'::JSON) AS roles, json_extract(g.data, \'$.evidence\') AS evidence
            FROM _issue_group_rows g
            LEFT JOIN link a ON a.left_core = '.$db->quote(V::GROUP_CORE).' AND a.left_id = g.local_id AND '.$linkType('a', V::GROUP_ARTICLE, V::ARTICLE_CORE).'
            LEFT JOIN link p ON p.left_core = '.$db->quote(V::GROUP_CORE).' AND p.left_id = g.local_id AND '.$linkType('p', V::GROUP_PARENT, V::GROUP_CORE).'
            WHERE g.data->>\'$.groupType\' IS DISTINCT FROM '.$db->quote(V::GROUP_TYPE_PAGE).'
            ORDER BY issue_id, page_index, ord');
        $db->exec('DROP TABLE _issue_group_rows');

        $db->exec(<<<'SQL'
            CREATE TABLE issue_group_role AS
            SELECT g.issue_id, g.page_index, g.ord AS group_ord, g.id AS group_id,
                   r.i AS ord, role->>'$.role' AS role, role->>'$.id' AS block_id,
                   (role->>'$.start')::INTEGER AS start_line, (role->>'$.end')::INTEGER AS end_line
            FROM issue_group g, unnest(range(json_array_length(g.roles)::BIGINT)) AS r(i),
                 LATERAL (SELECT json_extract(g.roles, '$[' || r.i || ']') AS role)
            ORDER BY g.issue_id, g.page_index, g.ord, r.i
            SQL);

        $counts = [];
        foreach (['issue_grouping', 'issue_page', 'issue_group', 'issue_group_role'] as $table) {
            $counts['main.'.$table] = (int) $db->query('SELECT count(*) FROM '.$table)->fetchColumn();
        }

        return $counts;
    }
}
