<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Dto\DuckDbS3Configuration;

/** Resolve only the immutable rendition pinned by the selected folio revision. */
final class DuckDbRenditionReader
{
    private array $open = [];

    public function __construct(private readonly DuckDbFolioReader $reader)
    {
    }

    public function reference(\PDO $folio, string $folioUri, string $issueId): array
    {
        $statement = $folio->prepare('SELECT * FROM _renditions WHERE issue_id = ?');
        $statement->execute([$issueId]);
        $ref = $statement->fetch(\PDO::FETCH_ASSOC);
        $prefix = substr($folioUri, 0, (int) strrpos($folioUri, '/') + 1).'rends/';
        if (!$ref || !preg_match('~^[A-Za-z0-9._-]+$~D', $issueId) || '..' === $issueId
            || !preg_match('~^[a-f0-9]{64}$~D', $ref['sha256'])
            || $ref['uri'] !== $prefix.$issueId.'/'.$ref['sha256'].'.rend'
            || $ref['build_id'] !== $folio->query('SELECT build_id FROM _folio_build')->fetchColumn()) {
            throw new \UnexpectedValueException('Missing or mismatched pinned newspaper rendition.');
        }

        return $ref;
    }

    public function open(\PDO $folio, string $folioUri, string $issueId, ?DuckDbS3Configuration $s3 = null): \PDO
    {
        $ref = $this->reference($folio, $folioUri, $issueId);
        $uri = $ref['uri'];
        if (!isset($this->open[$uri])) {
            $db = $this->reader->openRend($uri, $s3);
            $meta = $db->query('SELECT k,v FROM meta')->fetchAll(\PDO::FETCH_KEY_PAIR);
            if (($meta['buildId'] ?? null) !== $ref['build_id'] || ($meta['issue'] ?? null) !== $issueId
                || DuckDbRendSchema::VERSION !== (int) ($meta['layout'] ?? 0)) {
                throw new \UnexpectedValueException('Rend belongs to another source build or issue.');
            }
            // Bound connections for long-running indexing jobs.
            if (count($this->open) >= 2) {
                array_shift($this->open);
            }
            $this->open[$uri] = $db;
        }

        return $this->open[$uri];
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
