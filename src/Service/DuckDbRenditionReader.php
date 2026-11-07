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
     * One connection per paper/revision. All callers must filter issue-bearing tables explicitly;
     * no temporary views or other issue-specific session state may be installed on this connection.
     */
    public function open(\PDO $folio, string $folioUri, string $issueId, ?DuckDbS3Configuration $s3 = null): \PDO
    {
        $ref = $this->reference($folio, $folioUri, $issueId);
        $uri = $ref['uri'];
        $key = 'drend|'.$uri.'|'.($s3->host ?? '');
        if (null !== $this->pool) {
            $db = $this->pool->remember($key,
                fn (): \PDO => $this->reader->openRend($uri, $s3), $ref['build_id'], 'drend');
        } else {
            if (($this->open[$key]['build'] ?? null) !== $ref['build_id']) {
                unset($this->open[$key]);
                if (count($this->open) >= 2) {
                    array_shift($this->open);
                }
                $this->open[$key] = ['build' => $ref['build_id'], 'db' => $this->reader->openRend($uri, $s3)];
            }
            $db = $this->open[$key]['db'];
        }
        $this->validateIssue($db, $issueId, $ref['build_id']);

        return $db;
    }

    /** Check the build, layout and issue without mutating the shared connection. */
    public function validateIssue(\PDO $db, string $issueId, string $buildId): void
    {
        $catalog = '"'.str_replace('"', '""', (string) $db->query('SELECT current_database()')->fetchColumn()).'"';
        $meta = $db->query('SELECT k,v FROM '.$catalog.'.main.meta')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $present = $db->prepare('SELECT 1 FROM '.$catalog.'.main.item_geometry WHERE issue = ? LIMIT 1');
        $present->execute([$issueId]);
        if (($meta['buildId'] ?? null) !== $buildId || false === $present->fetchColumn()
            || DuckDbRendSchema::PAPER_VERSION !== (int) ($meta['layout'] ?? 0)) {
            throw new \UnexpectedValueException('Rend belongs to another source build or issue.');
        }
    }

    /** Restore exact geometry before using existing Row/Page presentation and grouping validation. */
    public function restore(\PDO $rend, array $items, array $pages, string $issueId): array
    {
        $result = [];
        foreach (['item' => $items, 'page' => $pages] as $table => $rows) {
            $statement = $rend->prepare('SELECT id, patch FROM '.$table.'_geometry WHERE issue = ?');
            $statement->execute([$issueId]);
            $patches = $statement->fetchAll(\PDO::FETCH_KEY_PAIR);
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
