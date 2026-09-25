<?php

declare(strict_types=1);

/**
 * Score retrieval settings for article windows against judged queries (article-queries.json).
 *
 *   php article-windows.php <article.jsonl> [--target=120 --max=250 | --whole] --json > windows.jsonl
 *   php article-search-eval.php windows.jsonl [--articles=article.jsonl] [--queries=article-queries.json]
 *                               [--cache=embeddings.jsonl] [--detail=<config>] [--keep]
 *
 * Every setting is scored at the ARTICLE level — what a reader gets back — so a ranking that shows the
 * same article three times is charged for it. Metrics, averaged over queries:
 *   MRR@10  1/rank of the first relevant article (0 if not in the top 10)
 *   Hit@1, Hit@5
 *   R@10    share of the relevant articles in the top 10 (denominator capped at 10)
 */

require __DIR__ . '/search-lib.php';

$opts = [
    'articles' => getenv('HOME') . '/platform/work/news/rappnews-digital/norm/article.jsonl',
    'queries' => __DIR__ . '/article-queries.json',
    'cache' => __DIR__ . '/.embeddings-' . MODEL . '.jsonl',
    'detail' => 'RRF bm25(h1.5)+knn',
];
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(\w+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        $args[] = $a;
    }
}
$file = $args[0] ?? exit("usage: php article-search-eval.php windows.jsonl [--articles= --queries= --cache= --detail= --keep]\n");

// --- judged queries → sets of full article ids ----------------------------------------------------
$articles = [];
foreach (new SplFileObject($opts['articles']) as $line) {
    if (trim($line) !== '') {
        $a = json_decode($line, true);
        $articles[$a['id']] = $a;
    }
}
$queries = json_decode(file_get_contents($opts['queries']), true, flags: JSON_THROW_ON_ERROR)['queries'];
foreach ($queries as &$q) {
    $rel = [];
    foreach ($q['rel'] ?? [] as $prefix) {
        $hits = array_values(array_filter(array_keys($articles), fn ($id) => str_starts_with($id, $prefix)));
        count($hits) === 1 || exit("ambiguous or unknown article prefix $prefix\n");
        $rel[] = $hits[0];
    }
    if (isset($q['relMatch'])) {
        foreach ($articles as $id => $a) {
            $hay = ($q['relField'] ?? null) === 'title' ? (string) $a['title'] : $a['title'] . "\n" . ($a['bodyText'] ?? '');
            if (preg_match_all('/' . $q['relMatch'] . '/iu', $hay) >= ($q['relMin'] ?? 1)) {
                $rel[] = $id;
            }
        }
    }
    $q['relSet'] = array_flip($rel);
}
unset($q);

// --- index -----------------------------------------------------------------------------------------
$windows = loadWindows($file);
$idx = buildIndex($windows, vectors($windows, $opts['cache']), (bool) ($opts['keep'] ?? false));
fprintf(STDERR, "index %s: %d windows over %d articles, %d queries\n\n", $idx, count($windows), count(array_unique(array_column($windows, 'rowId'))), count($queries));

// --- settings ----------------------------------------------------------------------------------------
$fieldsets = [
    'h3' => ['headline^3', 'subheads^2', 'body'],
    'h1.5' => ['headline^1.5', 'subheads^1.2', 'body'],
    'flat' => ['headline', 'subheads', 'body'],
];
$configs = [
    'BM25 h3 (probe default)' => fn ($r) => groupByArticle($r['h3']),
    'BM25 h1.5' => fn ($r) => groupByArticle($r['h1.5']),
    'BM25 flat' => fn ($r) => groupByArticle($r['flat']),
    'kNN' => fn ($r) => groupByArticle($r['knn']),
    // Fusing windows then taking articles in window order: what the first probe did.
    'RRF windows, ungrouped' => fn ($r) => array_map(fn ($f) => ['rowId' => $f['hit']['_source']['rowId'], 'hit' => $f['hit']],
        rrf(['b' => [array_map(fn ($h) => ['rowId' => $h['_id'], 'hit' => $h], $r['h1.5']), 1.0],
             'k' => [array_map(fn ($h) => ['rowId' => $h['_id'], 'hit' => $h], $r['knn']), 1.0]])),
    'RRF bm25(h3)+knn' => fn ($r) => rrf(['bm25' => [groupByArticle($r['h3']), 1.0], 'knn' => [groupByArticle($r['knn']), 1.0]]),
    'RRF bm25(h1.5)+knn' => fn ($r) => rrf(['bm25' => [groupByArticle($r['h1.5']), 1.0], 'knn' => [groupByArticle($r['knn']), 1.0]]),
    'RRF bm25(flat)+knn' => fn ($r) => rrf(['bm25' => [groupByArticle($r['flat']), 1.0], 'knn' => [groupByArticle($r['knn']), 1.0]]),
    'RRF bm25(h1.5)+knn×1.5' => fn ($r) => rrf(['bm25' => [groupByArticle($r['h1.5']), 1.0], 'knn' => [groupByArticle($r['knn']), 1.5]]),
    'RRF bm25(h1.5)×1.5+knn' => fn ($r) => rrf(['bm25' => [groupByArticle($r['h1.5']), 1.5], 'knn' => [groupByArticle($r['knn']), 1.0]]),
];

/** @return array{mrr: float, h1: int, h5: int, r10: float, first: ?int} */
function score(array $ranked, array $relSet): array
{
    $first = null;
    $found = [];
    foreach (array_slice($ranked, 0, 10) as $i => $a) {
        if (isset($relSet[$a['rowId']])) {
            $first ??= $i + 1;
            $found[$a['rowId']] = true;
        }
    }
    $found = count($found);

    return ['mrr' => $first ? 1 / $first : 0.0, 'h1' => (int) ($first === 1), 'h5' => (int) ($first !== null && $first <= 5),
        'r10' => $relSet ? $found / min(10, count($relSet)) : 0.0, 'first' => $first];
}

$results = [];   // config => list of per-query scores
$detail = [];
foreach ($queries as $qi => $q) {
    $raw = ['knn' => knn($idx, embedQuery($q['q']))];
    foreach ($fieldsets as $name => $fields) {
        $raw[$name] = bm25($idx, $q['q'], $fields);
    }
    foreach ($configs as $name => $rank) {
        $ranked = $rank($raw);
        $results[$name][$qi] = score($ranked, $q['relSet']);
        if ($name === $opts['detail']) {
            $detail[$qi] = $ranked[0] ?? null;
        }
    }
}

// --- report ------------------------------------------------------------------------------------------
$kinds = array_values(array_unique(array_column($queries, 'kind')));
$avg = fn (array $rows, string $k) => $rows ? array_sum(array_column($rows, $k)) / count($rows) : 0.0;
printf("%-26s %6s %6s %6s %6s   %s\n", 'setting', 'MRR@10', 'Hit@1', 'Hit@5', 'R@10', implode('  ', array_map(fn ($k) => str_pad("$k(" . count(array_filter($queries, fn ($q) => $q['kind'] === $k)) . ')', 11), $kinds)));
foreach ($results as $name => $rows) {
    $byKind = array_map(fn ($k) => sprintf('%-11s', sprintf('%.2f', $avg(array_values(array_intersect_key($rows, array_filter($queries, fn ($q) => $q['kind'] === $k))), 'mrr'))), $kinds);
    printf("%-26s %6.3f %6.2f %6.2f %6.2f   %s\n", $name, $avg($rows, 'mrr'), $avg($rows, 'h1'), $avg($rows, 'h5'), $avg($rows, 'r10'), implode('  ', $byKind));
}
echo "  (per-kind columns are MRR@10)\n\n";

printf("first relevant rank per query — BM25 h1.5 · kNN · %s — and the top result it returns\n", $opts['detail']);
foreach ($queries as $qi => $q) {
    $f = fn (string $c) => $results[$c][$qi]['first'] ?? '–';
    $top = $detail[$qi] ?? null;
    printf("  %-7s %-3s %-3s %-3s  %-52s → %s%s\n", $q['kind'], $f('BM25 h1.5'), $f('kNN'), $f($opts['detail']), mb_strimwidth($q['q'], 0, 52, '…'),
        isset($top['rowId'], $q['relSet'][$top['rowId']]) ? '✓ ' : '✗ ',
        $top ? mb_strimwidth($top['hit']['_source']['headline'] . ($top['hit']['_source']['subheads'] ? ' › ' . implode(' › ', $top['hit']['_source']['subheads']) : ''), 0, 60, '…') : '');
}
