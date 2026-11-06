<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Dto\DuckDbS3Configuration;

/** Resolve only the immutable rendition pinned by the selected folio revision. */
final class DuckDbRenditionReader
{
    private array $open = [];

    public function __construct(private readonly DuckDbFolioReader $reader, private readonly ?\Survos\DuckDbBundle\ConnectionPool $pool = null)
    {
    }

    /** The paper's rendition sits beside its dfolio: <folioCode>.dfolio (or <folioCode>.<language>.dfolio) -> <folioCode>.drend. */
    public static function rendUri(string $folioUri): string
    {
        $uri = preg_replace('~(?:\.[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*)?\.(?:dfolio|duckdb)$~D', '', $folioUri, 1, $replaced);
        if (1 !== $replaced) {
            throw new \UnexpectedValueException('Not a published DuckDB folio address.');
        }

        return $uri.'.drend';
    }

    public function reference(\PDO $folio, string $folioUri, string $issueId): array
    {
        $statement = $folio->prepare('SELECT * FROM _renditions WHERE issue_id = ?');
        $statement->execute([$issueId]);
        $ref = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!$ref || !preg_match('~^[A-Za-z0-9._-]+$~D', $issueId) || '..' === $issueId
            || $ref['uri'] !== self::rendUri($folioUri)
            || $ref['build_id'] !== $folio->query('SELECT build_id FROM _folio_build')->fetchColumn()) {
            throw new \UnexpectedValueException('Missing or mismatched pinned newspaper rendition.');
        }

        return $ref;
    }

    /**
     * One connection per issue onto the paper's single .drend, exposing that issue as plain page/block/crop/
     * item_geometry/page_geometry/meta views, so a reader queries it as if the issue had a file of its own.
     * The lookup is a filter on `issue`; over S3 only the row groups that hold the issue are fetched.
     */
    public function open(\PDO $folio, string $folioUri, string $issueId, ?DuckDbS3Configuration $s3 = null): \PDO
    {
        $ref = $this->reference($folio, $folioUri, $issueId);
        $uri = $ref['uri'];
        $key = $uri.'#'.$issueId;
        // Each issue's scoped connection is its own pool entry (the scope is session state), reused across requests in a worker.
        if (null !== $this->pool) {
            return $this->pool->remember('drend|'.$key.'|'.($s3->host ?? ''), fn (): \PDO => $this->scope($this->reader->openRend($uri, $s3), $issueId, $ref['build_id']), $ref['build_id'], 'drend');
        }
        if (!isset($this->open[$key])) {
            $db = $this->scope($this->reader->openRend($uri, $s3), $issueId, $ref['build_id']);
            // Bound connections for long-running indexing jobs.
            if (count($this->open) >= 2) {
                array_shift($this->open);
            }
            $this->open[$key] = $db;
        }

        return $this->open[$key];
    }

    /** Check that an opened .drend belongs to this source build and holds the issue, then expose the issue as plain views. */
    public function scope(\PDO $db, string $issueId, string $buildId): \PDO
    {
        $catalog = '"'.str_replace('"', '""', (string) $db->query('SELECT current_database()')->fetchColumn()).'"';
        $meta = $db->query('SELECT k,v FROM '.$catalog.'.main.meta')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $present = $db->prepare('SELECT 1 FROM '.$catalog.'.main.item_geometry WHERE issue = ? LIMIT 1');
        $present->execute([$issueId]);
        if (($meta['buildId'] ?? null) !== $buildId || false === $present->fetchColumn()
            || DuckDbRendSchema::PAPER_VERSION !== (int) ($meta['layout'] ?? 0)) {
            throw new \UnexpectedValueException('Rend belongs to another source build or issue.');
        }
        $issue = $db->quote($issueId);
        foreach (DuckDbRendSchema::ISSUE_COLUMNS as $table => $columns) {
            $db->exec('CREATE TEMP VIEW '.$table.' AS SELECT '.$columns.' FROM '.$catalog.'.main.'.$table.' WHERE issue = '.$issue);
        }
        $db->exec('CREATE OR REPLACE TEMP VIEW meta AS SELECT k, v FROM '.$catalog.'.main.meta UNION ALL SELECT \'issue\', '.$issue);

        return $db;
    }

    /** Restore exact geometry before using existing Row/Page presentation and grouping validation. */
    public function restore(\PDO $rend, array $items, array $pages): array
    {
        $result = [];
        foreach (['item' => $items, 'page' => $pages] as $table => $rows) {
            $patches = $rend->query('SELECT id, patch FROM '.$table.'_geometry')->fetchAll(\PDO::FETCH_KEY_PAIR);
            $result[] = array_map(static function (array $row) use ($table, $patches): array {
                if (!isset($patches[$row['id']])) {
                    throw new \UnexpectedValueException('Missing rendition row: '.$row['id']);
                }

                return NewspaperGeometry::restoreRow($table, $row, json_decode($patches[$row['id']], true, flags: JSON_THROW_ON_ERROR));
            }, $rows);
        }

        return $result;
    }
}
