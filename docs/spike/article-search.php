<?php

declare(strict_types=1);

/**
 * Search article windows with the settings article-search-eval.php picked, grouped by article.
 *
 *   php article-windows.php <article.jsonl> --json > windows.jsonl
 *   php article-search.php windows.jsonl --keep                    # build an index, print its name
 *   php article-search.php --index=<name> "query" ["query" ...]    # search it
 *   php article-search.php windows.jsonl "query" ...               # one-off: build, search, delete
 *
 * Settings (see ../segments-and-transcript-search.md, "Measured retrieval"): 120-word windows; BM25
 * over headline^1.5, subheads^1.2, body; kNN on window vectors; each list grouped to articles first,
 * then fused by rank with kNN weighted 1.5. Each article shows its best passage and where it sits.
 */

require __DIR__ . '/search-lib.php';

$opts = ['cache' => __DIR__ . '/.embeddings-' . MODEL . '.jsonl', 'size' => 8];
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(\w+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        $args[] = $a;
    }
}

if (isset($opts['index'])) {
    $idx = $opts['index'];
    $queries = $args;
} else {
    $file = array_shift($args) ?? exit("usage: php article-search.php windows.jsonl [--keep] [\"query\" ...] | --index=<name> \"query\" ...\n");
    $windows = loadWindows($file);
    $idx = buildIndex($windows, vectors($windows, $opts['cache']), (bool) ($opts['keep'] ?? false));
    fprintf(STDERR, "index %s (%d windows)%s\n", $idx, count($windows), ($opts['keep'] ?? false) ? ' — kept; search it with --index=' . $idx : '');
    $queries = $args;
}

foreach ($queries as $q) {
    $articles = rrf([
        'bm25' => [groupByArticle(bm25($idx, $q, ['headline^1.5', 'subheads^1.2', 'body'])), 1.0],
        'knn' => [groupByArticle(knn($idx, embedQuery($q))), 1.5],
    ]);
    printf("\n━━ %s\n", $q);
    foreach (array_slice($articles, 0, (int) $opts['size']) as $i => $a) {
        $s = $a['hit']['_source'];
        [$rowId, $range] = explode('~', $a['hit']['_id'], 2) + [1 => ''];
        printf("%2d. %s%s\n    %s · bytes %s · %s\n", $i + 1, $s['headline'], $s['subheads'] ? ' › ' . implode(' › ', $s['subheads']) : '',
            substr($rowId, 0, 8), $range, implode(' ', array_map(fn ($k, $v) => "$k#$v", array_keys($a['ranks']), $a['ranks'])));
        if ($hl = $a['hit']['highlight']['body'][0] ?? null) {
            printf("    ↳ %s\n", preg_replace('/\s+/', ' ', strip_tags(str_replace(['<em>', '</em>'], ['[', ']'], $hl))));
        }
    }
}
