<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/** Produces issue renditions from the SAME pinned source transaction as the .dfolio. */
final class DuckDbRenditionExporter
{
    /** @param callable(string,string):array{uri:string,sha256:string,bytes:int} $publish
     * @return list<array{issue_id:string,uri:string,sha256:string,bytes:int,build_id:string}>
     */
    public function export(\PDO $source, string $buildId, string $directory, callable $publish): array
    {
        $refs = [];
        $itemCount = $pageCount = 0;
        if (false !== $source->query("SELECT left_id FROM link WHERE left_core = 'article' AND right_core = 'doc' GROUP BY left_id HAVING count(DISTINCT right_id) > 1 LIMIT 1")->fetchColumn()) {
            throw new \RuntimeException('An article cannot belong to multiple issue renditions.');
        }
        $docs = $source->query("SELECT id, local_id, core_id FROM item WHERE core_id LIKE '%:doc' ORDER BY id");
        $records = $source->prepare("SELECT * FROM item WHERE id = ? OR id IN
            (SELECT ? || left_id FROM link WHERE left_core = 'article' AND right_core = 'doc' AND right_id = ?)");
        $pages = $source->prepare("SELECT * FROM page WHERE row_id = ? OR row_id IN
            (SELECT ? || left_id FROM link WHERE left_core = 'article' AND right_core = 'doc' AND right_id = ?)");
        foreach ($docs as $issue) {
            $articlePrefix = substr($issue['core_id'], 0, -3).'article:';
            $args = [$issue['id'], $articlePrefix, $issue['local_id']];
            $path = $directory.'/issue.rend';
            $db = new \PDO('duckdb:'.$path);
            $db->beginTransaction();
            DuckDbRendSchema::create($db);
            $db->prepare('INSERT INTO meta VALUES (?,?),(?,?),(?,?)')->execute([
                'issue', $issue['local_id'], 'buildId', $buildId, 'layout', (string) DuckDbRendSchema::VERSION]);
            $records->execute($args);
            $itemCount += $this->insertGeometry($db, 'item', $records);
            $pages->execute($args);
            $geometry = $db->prepare('INSERT INTO page_geometry VALUES (?,?)');
            $insertPage = $db->prepare('INSERT INTO page VALUES (?,?,?,?,NULL)');
            $insertBlock = $db->prepare('INSERT INTO block VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($pages as $page) {
                ++$pageCount;
                [, $patch] = NewspaperGeometry::splitRow('page', $page);
                $geometry->execute([$page['id'], json_encode($patch, JSON_THROW_ON_ERROR)]);
                if ($page['row_id'] !== $issue['id']) {
                    continue;
                }
                $insertPage->execute([$page['page_index'], $page['width'], $page['height'], $page['source_url'] ?? $page['url']]);
                $layout = json_decode($page['layout'] ?? '[]', true, flags: JSON_THROW_ON_ERROR) ?? [];
                foreach (array_is_list($layout) ? $layout : ($layout['blocks'] ?? []) as $ord => $block) {
                    $box = $block['bbox'] ?? null;
                    if (!is_array($box) || !isset($box['x'], $box['y'], $box['width'], $box['height'])) {
                        continue;
                    }
                    $insertBlock->execute([$page['page_index'].':'.($block['id'] ?? ''), $page['page_index'],
                        $box['x'], $box['y'], $box['width'], $box['height'], $block['role'] ?? null, $block['type'] ?? null,
                        $block['confidence'] ?? null, $block['text'] ?? '', $ord, $block['fontSize'] ?? null,
                        $block['maxFontSize'] ?? null, isset($block['paragraphStarts']) ? json_encode($block['paragraphStarts'], JSON_THROW_ON_ERROR) : null]);
                }
            }
            unset($geometry, $insertPage, $insertBlock);
            $db->commit();
            $db->exec('CHECKPOINT');
            $db = null;
            if (is_file($path.'.wal')) {
                throw new \RuntimeException('Rend has an unmerged WAL.');
            }
            $remote = $publish($path, $issue['local_id']);
            if (!str_ends_with($remote['uri'], '/'.$remote['sha256'].'.rend')
                || $remote['sha256'] !== hash_file('sha256', $path) || $remote['bytes'] !== filesize($path)) {
                throw new \RuntimeException('Rend publication identity mismatch.');
            }
            $refs[] = ['issue_id' => $issue['local_id'], ...$remote, 'build_id' => $buildId];
            unlink($path);
        }
        if ([] === $refs) {
            throw new \RuntimeException('Newspaper export requires issue rows.');
        }
        if ($itemCount !== (int) $source->query('SELECT count(*) FROM item')->fetchColumn()
            || $pageCount !== (int) $source->query('SELECT count(*) FROM page')->fetchColumn()) {
            throw new \RuntimeException('Every newspaper row and page must belong to exactly one rendition.');
        }

        return $refs;
    }

    private function insertGeometry(\PDO $db, string $table, \PDOStatement $rows): int
    {
        $insert = $db->prepare('INSERT INTO '.$table.'_geometry VALUES (?,?)');
        $count = 0;
        foreach ($rows as $row) {
            ++$count;
            [, $patch] = NewspaperGeometry::splitRow($table, $row);
            $insert->execute([$row['id'], json_encode($patch, JSON_THROW_ON_ERROR)]);
        }

        return $count;
    }
}
