<?php

declare(strict_types=1);

/**
 * Shared by article-index-probe.php and article-search-eval.php: HTTP, embeddings (local Ollama,
 * cached by xxh3(model + text)), the window index definition, and article-level rank fusion.
 *
 * nomic-embed-text is used so the spikes have vectors; it is not the embedding decision.
 */

const ES = 'http://localhost:9200';
const OLLAMA = 'http://localhost:11434/api/embed';
const MODEL = 'nomic-embed-text';
const DIMS = 768;

function http(string $method, string $url, mixed $body = null, string $type = 'application/json'): array
{
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => "Content-Type: $type\r\n",
        'content' => $body === null ? '' : (is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE)),
        'ignore_errors' => true,
        'timeout' => 300,
    ]]);
    $raw = file_get_contents($url, false, $ctx);

    return json_decode((string) $raw, true) ?? ['raw' => $raw];
}

/** Markdown → plain text for analysis and embedding; offsets stay on the stored window. */
function plain(string $md): string
{
    $s = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $md);
    $s = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $s);
    $s = preg_replace('/^#{1,6}\s+/m', '', $s);

    return trim(str_replace(['**', '__', '*', '_'], '', $s));
}

/** @param list<string> $texts @return list<list<float>> */
function embed(array $texts): array
{
    $r = http('POST', OLLAMA, ['model' => MODEL, 'input' => $texts]);

    return $r['embeddings'] ?? throw new RuntimeException('ollama: ' . json_encode($r));
}

/** @return list<float> */
function embedQuery(string $q): array
{
    // nomic-embed-text is trained with task prefixes; documents get "search_document: ".
    return embed(['search_query: ' . $q])[0];
}

/** @return list<array> windows from article-windows.php --json, each with embedText + cache key */
function loadWindows(string $file): array
{
    $windows = [];
    foreach (new SplFileObject($file) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $w = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        // What to embed is itself a choice; here: headline › subheads, then the body text.
        $w['embedText'] = 'search_document: ' . implode(' › ', array_filter([$w['headline'], ...$w['subheads']])) . "\n\n" . plain($w['text']);
        $w['key'] = hash('xxh3', MODEL . "\0" . $w['embedText']);
        $windows[] = $w;
    }

    return $windows;
}

/** @return array<string, list<float>> key => vector, embedding (and caching) whatever is missing */
function vectors(array $windows, string $cacheFile): array
{
    $cache = [];
    if (is_file($cacheFile)) {
        foreach (new SplFileObject($cacheFile) as $line) {
            if (trim($line) !== '') {
                [$k, $v] = json_decode($line, true);
                $cache[$k] = $v;
            }
        }
    }
    $todo = array_values(array_filter($windows, fn ($w) => !isset($cache[$w['key']])));
    $t0 = microtime(true);
    $out = fopen($cacheFile, 'a');
    foreach (array_chunk($todo, 32) as $chunk) {
        foreach (embed(array_column($chunk, 'embedText')) as $i => $vec) {
            $cache[$chunk[$i]['key']] = $vec;
            fwrite($out, json_encode([$chunk[$i]['key'], $vec]) . "\n");
        }
    }
    fclose($out);
    fprintf(STDERR, "%d windows, %d embedded now in %.1fs, %d from cache\n", count($windows), count($todo), microtime(true) - $t0, count($windows) - count($todo));

    return $cache;
}

/**
 * "fifty" ⇄ "50": the standard tokenizer keeps both, and nothing relates them. Search-time only.
 *
 * @return list<string>
 */
function numberSynonyms(): array
{
    $words = ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
        'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty'];
    $out = [];
    foreach ($words as $i => $w) {
        $out[] = sprintf('%s, %d', $w, $i + 1);
    }
    foreach (['thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90, 'hundred' => 100] as $w => $n) {
        $out[] = "$w, $n";
    }

    return $out;
}

/** Create and fill a probe index; it is deleted at shutdown unless $keep. */
function buildIndex(array $windows, array $vectors, bool $keep = false): string
{
    $idx = 'probe_articles_' . time() . '_' . getmypid();
    if (!$keep) {
        register_shutdown_function(fn () => fprintf(STDERR, "deleted %s: %s\n", $idx, json_encode(http('DELETE', ES . "/$idx"))));
    }
    $created = http('PUT', ES . "/$idx", [
        'settings' => [
            'number_of_shards' => 1, 'number_of_replicas' => 0,
            'analysis' => [
                'filter' => [
                    'en_stop' => ['type' => 'stop', 'stopwords' => '_english_'],
                    'en_possessive' => ['type' => 'stemmer', 'language' => 'possessive_english'],
                    'en_stem' => ['type' => 'stemmer', 'language' => 'english'],
                    // Search-time only, so the list can grow without reindexing. Seed entries, not a vocabulary.
                    'en_syn' => ['type' => 'synonym_graph', 'synonyms' => [
                        'childcare, child care, day care, daycare',
                        'firefighter, fire fighter, fireman',
                        'broadband, high speed internet, internet access',
                        ...numberSynonyms(),
                    ]],
                ],
                'analyzer' => [
                    'en_index' => ['tokenizer' => 'standard', 'filter' => ['en_possessive', 'lowercase', 'en_stop', 'en_stem']],
                    'en_search' => ['tokenizer' => 'standard', 'filter' => ['en_possessive', 'lowercase', 'en_syn', 'en_stop', 'en_stem']],
                ],
            ],
        ],
        'mappings' => ['properties' => [
            'rowId' => ['type' => 'keyword'],
            'headline' => ['type' => 'text', 'analyzer' => 'en_index', 'search_analyzer' => 'en_search'],
            'subheads' => ['type' => 'text', 'analyzer' => 'en_index', 'search_analyzer' => 'en_search'],
            'body' => ['type' => 'text', 'analyzer' => 'en_index', 'search_analyzer' => 'en_search', 'term_vector' => 'with_positions_offsets'],
            'bylines' => ['type' => 'keyword'],
            'sections' => ['type' => 'keyword'],
            'date' => ['type' => 'date', 'format' => 'yyyy-MM-dd||yyyy-MM||yyyy'],
            'from' => ['type' => 'integer', 'index' => false],
            'to' => ['type' => 'integer', 'index' => false],
            'vec' => ['type' => 'dense_vector', 'dims' => DIMS, 'index' => true, 'similarity' => 'cosine'],
        ]],
    ]);
    isset($created['acknowledged']) || throw new RuntimeException('create failed: ' . json_encode($created));

    foreach (array_chunk($windows, 500) as $chunk) {
        $bulk = '';
        foreach ($chunk as $w) {
            $bulk .= json_encode(['index' => ['_index' => $idx, '_id' => $w['id']]]) . "\n";
            $bulk .= json_encode([
                'rowId' => $w['rowId'], 'headline' => $w['headline'], 'subheads' => $w['subheads'], 'body' => plain($w['text']),
                'bylines' => $w['bylines'], 'sections' => $w['sections'], 'date' => $w['date'],
                'from' => $w['from'], 'to' => $w['to'], 'vec' => $vectors[$w['key']],
            ], JSON_UNESCAPED_UNICODE) . "\n";
        }
        $r = http('POST', ES . '/_bulk', $bulk, 'application/x-ndjson');
        empty($r['errors']) || throw new RuntimeException('bulk errors: ' . json_encode(array_slice($r['items'] ?? [], 0, 2)));
    }
    http('POST', ES . "/$idx/_refresh");

    return $idx;
}

/** @return list<array> window hits for a BM25 query with the given field boosts */
function bm25(string $idx, string $q, array $fields, int $size = 50): array
{
    return http('POST', ES . "/$idx/_search", [
        'size' => $size, '_source' => ['rowId', 'headline', 'subheads'],
        'query' => ['multi_match' => ['query' => $q, 'fields' => $fields, 'type' => 'best_fields']],
        'highlight' => ['fields' => ['body' => ['fragment_size' => 140, 'number_of_fragments' => 1]]],
    ])['hits']['hits'] ?? [];
}

/** @return list<array> window hits for a kNN query */
function knn(string $idx, array $vec, int $k = 50): array
{
    return http('POST', ES . "/$idx/_search", [
        'size' => $k, '_source' => ['rowId', 'headline', 'subheads'],
        'knn' => ['field' => 'vec', 'query_vector' => $vec, 'k' => $k, 'num_candidates' => max(200, 4 * $k)],
    ])['hits']['hits'] ?? [];
}

/**
 * Collapse a ranked window list to a ranked article list: an article ranks where its best window
 * ranks, and carries that window as its passage.
 *
 * @return list<array{rowId: string, hit: array}>
 */
function groupByArticle(array $hits): array
{
    $out = [];
    foreach ($hits as $h) {
        $out[$h['_source']['rowId']] ??= ['rowId' => $h['_source']['rowId'], 'hit' => $h];
    }

    return array_values($out);
}

/**
 * Reciprocal rank fusion of article lists (fuse after grouping, so one long article with many
 * matching windows cannot crowd the list). Scores: Σ weight / (k + rank).
 *
 * @param array<string, array{0: list<array>, 1: float}> $lists name => [article list, weight]
 * @return list<array{rowId: string, hit: array, score: float, ranks: array<string,int>}>
 */
function rrf(array $lists, int $k = 60): array
{
    $fused = [];
    foreach ($lists as $name => [$articles, $weight]) {
        foreach ($articles as $rank => $a) {
            $fused[$a['rowId']] ??= ['rowId' => $a['rowId'], 'hit' => $a['hit'], 'score' => 0.0, 'ranks' => []];
            $fused[$a['rowId']]['score'] += $weight / ($k + $rank + 1);
            $fused[$a['rowId']]['ranks'][$name] = $rank + 1;
            // Prefer a passage that carries a BM25 highlight.
            if (!isset($fused[$a['rowId']]['hit']['highlight']) && isset($a['hit']['highlight'])) {
                $fused[$a['rowId']]['hit'] = $a['hit'];
            }
        }
    }
    usort($fused, fn ($a, $b) => $b['score'] <=> $a['score']);

    return $fused;
}
