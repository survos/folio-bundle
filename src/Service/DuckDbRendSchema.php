<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/** DuckDB rendition contract; legacy SQLite .rend files remain a separate read format. */
final class DuckDbRendSchema
{
    public const int VERSION = 3;

    public static function create(\PDO $db): void
    {
        $db->exec('CREATE TABLE meta(k VARCHAR PRIMARY KEY, v VARCHAR)');
        $db->exec('CREATE TABLE page(idx INTEGER PRIMARY KEY, width INTEGER, height INTEGER, source_url VARCHAR, jp2 VARCHAR)');
        $db->exec('CREATE TABLE block(id VARCHAR PRIMARY KEY, page INTEGER, x INTEGER, y INTEGER, w INTEGER, h INTEGER, role VARCHAR, type VARCHAR, confidence DOUBLE, ocr VARCHAR, ord INTEGER, font_size DOUBLE, max_font_size DOUBLE, paragraph_starts VARCHAR)');
        $db->exec('CREATE TABLE crop(block_id VARCHAR PRIMARY KEY, preset VARCHAR, mime VARCHAR, bytes BLOB)');
        $db->exec('CREATE TABLE item_geometry(id VARCHAR PRIMARY KEY, patch VARCHAR)');
        $db->exec('CREATE TABLE page_geometry(id VARCHAR PRIMARY KEY, patch VARCHAR)');
    }
}
