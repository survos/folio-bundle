<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/**
 * DuckDB rendition contracts. Two shapes share this class:
 *  - create(): a local per-issue .rend (layout 3), written by harvest's rend builder and read by Ink's local RendReader;
 *  - createPaper(): the published .drend (layout 4), described below.
 *
 * The published .drend: ONE file per paper, <folioCode>.drend, holding the geometry of every issue.
 * Each table carries an `issue` column. Readers filter it explicitly on a connection pooled per paper/build.
 * No primary keys on purpose: DuckDB loads a key's index when it opens the file, which over S3 would cost far more than
 * the lookup saves. Rows are written issue by issue, so the per-row-group min/max on `issue` lets a read skip the rest.
 * Legacy SQLite .rend files remain a separate read format.
 */
final class DuckDbRendSchema
{
    /** Layout of a local per-issue .rend. */
    public const int VERSION = 3;

    /** Layout of the published per-paper .drend. */
    public const int PAPER_VERSION = 4;

    /** The columns a reader sees for one issue: every table minus its `issue` column. */
    public const array ISSUE_COLUMNS = [
        'page' => 'idx, width, height, source_url, jp2',
        'block' => 'id, page, x, y, w, h, role, type, confidence, ocr, ord, font_size, max_font_size, paragraph_starts',
        'crop' => 'block_id, preset, mime, bytes',
        'item_geometry' => 'id, patch',
        'page_geometry' => 'id, patch',
    ];

    /** A per-issue .rend: no issue column, the file is the issue. */
    public static function create(\PDO $db): void
    {
        $db->exec('CREATE TABLE meta(k VARCHAR PRIMARY KEY, v VARCHAR)');
        $db->exec('CREATE TABLE page(idx INTEGER PRIMARY KEY, width INTEGER, height INTEGER, source_url VARCHAR, jp2 VARCHAR)');
        $db->exec('CREATE TABLE block(id VARCHAR PRIMARY KEY, page INTEGER, x INTEGER, y INTEGER, w INTEGER, h INTEGER, role VARCHAR, type VARCHAR, confidence DOUBLE, ocr VARCHAR, ord INTEGER, font_size DOUBLE, max_font_size DOUBLE, paragraph_starts VARCHAR)');
        $db->exec('CREATE TABLE crop(block_id VARCHAR PRIMARY KEY, preset VARCHAR, mime VARCHAR, bytes BLOB)');
        $db->exec('CREATE TABLE item_geometry(id VARCHAR PRIMARY KEY, patch VARCHAR)');
        $db->exec('CREATE TABLE page_geometry(id VARCHAR PRIMARY KEY, patch VARCHAR)');
    }

    /** The published .drend: every table keyed by `issue`, no primary keys. */
    public static function createPaper(\PDO $db): void
    {
        $db->exec('CREATE TABLE meta(k VARCHAR PRIMARY KEY, v VARCHAR)');
        $db->exec('CREATE TABLE page(issue VARCHAR NOT NULL, idx INTEGER, width INTEGER, height INTEGER, source_url VARCHAR, jp2 VARCHAR)');
        $db->exec('CREATE TABLE block(issue VARCHAR NOT NULL, id VARCHAR, page INTEGER, x INTEGER, y INTEGER, w INTEGER, h INTEGER, role VARCHAR, type VARCHAR, confidence DOUBLE, ocr VARCHAR, ord INTEGER, font_size DOUBLE, max_font_size DOUBLE, paragraph_starts VARCHAR)');
        $db->exec('CREATE TABLE crop(issue VARCHAR NOT NULL, block_id VARCHAR, preset VARCHAR, mime VARCHAR, bytes BLOB)');
        $db->exec('CREATE TABLE item_geometry(issue VARCHAR NOT NULL, id VARCHAR, patch VARCHAR)');
        $db->exec('CREATE TABLE page_geometry(issue VARCHAR NOT NULL, id VARCHAR, patch VARCHAR)');
    }
}
