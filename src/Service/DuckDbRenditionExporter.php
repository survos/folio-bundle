<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/**
 * Produces the paper's rendition (<folioCode>.drend, one DuckDB file for every issue) from the SAME pinned source
 * transaction as the .dfolio.
 */
final class DuckDbRenditionExporter
{
    /** Issues written between commits: an open transaction holds its rows in memory. */
    private const int COMMIT_EVERY = 50;

    /** Bytes of JSONL per INSERT when bulk-loading the rendition's tables. */
    private const int INGEST_CHUNK_BYTES = 32 * 1024 * 1024;

    /** @param callable(string):array{uri:string,bytes:int} $publish publishes the one paper rend
     * @return list<array{issue_id:string,uri:string,sha256:null,bytes:int,build_id:string}> one pin per issue, all naming the same object
     */
    public function export(\PDO $source, string $buildId, string $directory, callable $publish): array
    {
        $issues = [];
        $itemCount = $pageCount = 0;
        if (false !== $source->query("SELECT left_id FROM link WHERE left_core = 'article' AND right_core = 'doc' GROUP BY left_id HAVING count(DISTINCT right_id) > 1 LIMIT 1")->fetchColumn()) {
            throw new \RuntimeException('An article cannot belong to multiple issue renditions.');
        }
        $docs = $source->query("SELECT id, local_id, core_id FROM item WHERE core_id LIKE '%:doc' ORDER BY id");
        $records = $source->prepare("SELECT * FROM item WHERE id = ? OR id IN
            (SELECT ? || left_id FROM link WHERE left_core = 'article' AND right_core = 'doc' AND right_id = ?)");
        $pages = $source->prepare("SELECT * FROM page WHERE row_id = ? OR row_id IN
            (SELECT ? || left_id FROM link WHERE left_core = 'article' AND right_core = 'doc' AND right_id = ?)");
        $path = $directory.'/paper.drend';
        $db = new \PDO('duckdb:'.$path);
        $db->beginTransaction();
        DuckDbRendSchema::createPaper($db);
        $db->prepare('INSERT INTO meta VALUES (?,?),(?,?)')->execute(['buildId', $buildId, 'layout', (string) DuckDbRendSchema::PAPER_VERSION]);
        // DuckDB is columnar: one prepared INSERT per row runs at ~5,000 rows/s, the same rows through one
        // INSERT ... read_ndjson run at ~340,000 rows/s. Rows are appended to a JSONL file per table and each file is
        // inserted in one statement once it passes a chunk size (rows stay in issue order, so an issue stays contiguous).
        $shape = [
            'page' => ['issue VARCHAR, idx INTEGER, width INTEGER, height INTEGER, source_url VARCHAR', 'issue, idx, width, height, source_url, NULL'],
            'block' => ['issue VARCHAR, id VARCHAR, page INTEGER, x DOUBLE, y DOUBLE, w DOUBLE, h DOUBLE, role VARCHAR, type VARCHAR, confidence DOUBLE, ocr VARCHAR, ord INTEGER, font_size DOUBLE, max_font_size DOUBLE, paragraph_starts VARCHAR',
                'issue, id, page, CAST(x AS INTEGER), CAST(y AS INTEGER), CAST(w AS INTEGER), CAST(h AS INTEGER), role, type, confidence, ocr, ord, font_size, max_font_size, paragraph_starts'],
            'item_geometry' => ['issue VARCHAR, id VARCHAR, patch VARCHAR', 'issue, id, patch'],
            'page_geometry' => ['issue VARCHAR, id VARCHAR, patch VARCHAR', 'issue, id, patch'],
        ];
        $sinks = [];
        $flush = static function (string $table) use (&$sinks, $db, $shape): void {
            if (!isset($sinks[$table])) {
                return;
            }
            fclose($sinks[$table]['handle']);
            $columns = implode(', ', array_map(static fn (string $c): string => preg_replace('/^(\w+) (\w+)$/', "$1: '$2'", trim($c)), explode(',', $shape[$table][0])));
            $db->exec('INSERT INTO '.$table.' SELECT '.$shape[$table][1].' FROM read_ndjson('.$db->quote($sinks[$table]['path']).', columns = {'.$columns.'}, maximum_object_size = 268435456)');
            unlink($sinks[$table]['path']);
            unset($sinks[$table]);
        };
        $emit = static function (string $table, array $row) use (&$sinks, $directory, $flush): void {
            $sinks[$table] ??= ['path' => $directory.'/rend-'.$table.'.jsonl', 'handle' => fopen($directory.'/rend-'.$table.'.jsonl', 'wb') ?: throw new \RuntimeException('Cannot write rend rows.'), 'bytes' => 0];
            $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
            fwrite($sinks[$table]['handle'], $line);
            $sinks[$table]['bytes'] += strlen($line);
            if ($sinks[$table]['bytes'] >= self::INGEST_CHUNK_BYTES) {
                $flush($table);
            }
        };
        foreach ($docs as $issue) {
            $name = $issue['local_id'];
            $articlePrefix = substr($issue['core_id'], 0, -3).'article:';
            $args = [$issue['id'], $articlePrefix, $name];
            // Rows go in issue by issue, so each issue's rows are contiguous and a read of one issue skips the rest.
            $records->execute($args);
            foreach ($records as $row) {
                ++$itemCount;
                [, $patch] = NewspaperGeometry::splitRow('item', $row);
                $emit('item_geometry', ['issue' => $name, 'id' => $row['id'], 'patch' => json_encode($patch, JSON_THROW_ON_ERROR)]);
            }
            $pages->execute($args);
            foreach ($pages as $page) {
                ++$pageCount;
                [, $patch] = NewspaperGeometry::splitRow('page', $page);
                $emit('page_geometry', ['issue' => $name, 'id' => $page['id'], 'patch' => json_encode($patch, JSON_THROW_ON_ERROR)]);
                if ($page['row_id'] !== $issue['id']) {
                    continue;
                }
                $emit('page', ['issue' => $name, 'idx' => $page['page_index'], 'width' => $page['width'], 'height' => $page['height'], 'source_url' => $page['source_url'] ?? $page['url']]);
                $layout = json_decode($page['layout'] ?? '[]', true, flags: JSON_THROW_ON_ERROR) ?? [];
                foreach (array_is_list($layout) ? $layout : ($layout['blocks'] ?? []) as $ord => $block) {
                    $box = $block['bbox'] ?? null;
                    if (!is_array($box) || !isset($box['x'], $box['y'], $box['width'], $box['height'])) {
                        continue;
                    }
                    $emit('block', ['issue' => $name, 'id' => $page['page_index'].':'.($block['id'] ?? ''), 'page' => $page['page_index'],
                        'x' => $box['x'], 'y' => $box['y'], 'w' => $box['width'], 'h' => $box['height'], 'role' => $block['role'] ?? null, 'type' => $block['type'] ?? null,
                        'confidence' => $block['confidence'] ?? null, 'ocr' => $block['text'] ?? '', 'ord' => $ord, 'font_size' => $block['fontSize'] ?? null,
                        'max_font_size' => $block['maxFontSize'] ?? null, 'paragraph_starts' => isset($block['paragraphStarts']) ? json_encode($block['paragraphStarts'], JSON_THROW_ON_ERROR) : null]);
                }
            }
            $issues[] = $name;
            if (0 === count($issues) % self::COMMIT_EVERY) {
                $db->commit();
                $db->beginTransaction();
            }
        }
        foreach (array_keys($shape) as $table) {
            $flush($table);
        }
        $db->commit();
        $db->exec('CHECKPOINT');
        $db = null;
        if (is_file($path.'.wal')) {
            throw new \RuntimeException('Rend has an unmerged WAL.');
        }
        if ([] === $issues) {
            throw new \RuntimeException('Newspaper export requires issue rows.');
        }
        if ($itemCount !== (int) $source->query('SELECT count(*) FROM item')->fetchColumn()
            || $pageCount !== (int) $source->query('SELECT count(*) FROM page')->fetchColumn()) {
            throw new \RuntimeException('Every newspaper row and page must belong to exactly one rendition.');
        }
        // Published once, as <folioCode>.drend, replaced in place: the pin for an issue is the address and size, and
        // the build id inside the file says which source build it belongs to.
        $remote = $publish($path);
        if (!str_ends_with($remote['uri'], '.drend') || $remote['bytes'] !== filesize($path)) {
            throw new \RuntimeException('Rend publication identity mismatch.');
        }
        unlink($path);

        return array_map(static fn (string $issue): array => ['issue_id' => $issue, 'uri' => $remote['uri'], 'sha256' => null,
            'bytes' => $remote['bytes'], 'build_id' => $buildId], $issues);
    }
}
