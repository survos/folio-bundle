<?php

declare(strict_types=1);

/**
 * Spike: windows for an OCR newspaper folio, at block tier or full tier, in the same JSONL shape
 * article-windows.php writes, so article-search-eval.php can score either.
 *
 *   php ocr-windows.php block <basic.folio> <out-prefix>
 *   php ocr-windows.php full  <basic.folio> <enhanced.folio> <out-prefix> [--no-ads]
 *
 * --issues=<regex> keeps only rows whose id matches (e.g. 'rappnews-1996-'), for scoring a slice.
 *
 * Writes <out-prefix>.windows.jsonl and <out-prefix>.articles.jsonl (id, title, bodyText — what the
 * eval resolves relevance against).
 *
 *   block  every basic row is one block. Untitled: a block's title is whatever OCR left at the top
 *          of the fragment, so it is not given to the index as a headline.
 *   full   every stitched story (headline = its first segment, body = the rest), plus every basic
 *          block that no story claimed — the Row → Block model: stories where they exist, loose
 *          blocks everywhere else.
 *
 * --no-ads leaves out stitched groups the enhancement typed `advertisement` (their blocks are left
 * out with them: an ad group's blocks are ad copy, not loose news).
 *
 * OCR text has no paragraphs worth trusting, so units are sentences (php-sentence), packed by the
 * same packer and sizes as the digital articles (target 120 / max 250 / min 40 words).
 */

require getenv('HOME') . '/sites/lingua/vendor/autoload.php';
require __DIR__ . '/windows-lib.php';

use Vanderlee\Sentence\Sentence;

const TARGET = 120, MAX = 250, MIN = 40;

[$mode, $basic] = [$argv[1] ?? null, $argv[2] ?? null];
$enhanced = $mode === 'full' ? ($argv[3] ?? null) : null;
$out = $mode === 'full' ? ($argv[4] ?? null) : ($argv[3] ?? null);
if (!in_array($mode, ['block', 'full'], true) || $basic === null || $out === null || ($mode === 'full' && $enhanced === null)) {
    fwrite(STDERR, "usage: php ocr-windows.php block <basic.folio> <out-prefix> | full <basic.folio> <enhanced.folio> <out-prefix>\n");
    exit(1);
}

function rows(string $folio): Generator
{
    $pdo = new PDO('sqlite:file:' . $folio . '?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach ($pdo->query("SELECT id, dto_data, extras FROM item WHERE core_id LIKE '%:article' ORDER BY id") as $r) {
        yield [$r['id'], json_decode($r['dto_data'], true) ?? [], json_decode($r['extras'] ?? 'null', true) ?? []];
    }
}

/** "{issueId}|{page}|{sourceBlockId}" for every anchor of every segment. */
function anchorKeys(array $extras): array
{
    $keys = [];
    foreach ($extras['segments'] ?? [] as $s) {
        foreach ($s['anchors'] ?? [] as $a) {
            $keys[] = sprintf('%s|%d|%s', $extras['issueId'] ?? '', $a['page'] ?? -1, $a['sourceBlockId'] ?? '');
        }
    }

    return $keys;
}

function clean(string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
}

$splitter = new Sentence();
$fw = fopen("$out.windows.jsonl", 'w');
$fa = fopen("$out.articles.jsonl", 'w');
$counts = ['stories' => 0, 'blocks' => 0, 'claimed' => 0, 'windows' => 0, 'empty' => 0, 'ads' => 0];
$noAds = in_array('--no-ads', $argv, true);
$issues = null;
foreach ($argv as $a) { if (str_starts_with($a, '--issues=')) { $issues = '/' . substr($a, 9) . '/'; } }

/** Pack one unit of meaning into windows and write them. */
$emit = function (string $id, string $headline, string $body, ?string $date, string $tier) use ($splitter, $fw, $fa, &$counts): void {
    fwrite($fa, json_encode(['id' => $id, 'title' => $headline, 'bodyText' => $body, 'tier' => $tier], JSON_UNESCAPED_UNICODE) . "\n");
    if (words($body) === 0 && $headline === '') {
        $counts['empty']++;
        return;
    }
    $units = [];
    $cursor = 0;
    foreach ($splitter->split($body) as $sentence) {
        $t = trim($sentence);
        if ($t === '') { continue; }
        // php-sentence may normalise a sentence (runs of dashes, dots), so it is not always a literal
        // substring: fall back to the cursor, and never let offsets run past the text.
        $at = $cursor < strlen($body) ? strpos($body, $t, $cursor) : false;
        $at = $at === false ? min($cursor, strlen($body)) : $at;
        $cursor = min(strlen($body), $at + strlen($t));
        if ($cursor <= $at) { continue; }
        $t = substr($body, $at, $cursor - $at);
        $units[] = ['kind' => 'sentence', 'speaker' => null, 'cut' => Q_SPEAKER, 'text' => $t, 'from' => $at, 'to' => $cursor, 'words' => words($t)];
    }
    // A headline with no body is still findable by its headline.
    $windows = $units === [] ? [[['text' => '', 'from' => 0, 'to' => 0, 'words' => 0]]] : packWindows($units, TARGET, MAX, MIN);
    foreach ($windows as $w) {
        $first = $w[0];
        $last = $w[array_key_last($w)];
        fwrite($fw, json_encode([
            'id' => sprintf('%s~%d-%d', $id, $first['from'], $last['to']), 'rowId' => $id,
            'headline' => $headline, 'subheads' => [], 'bylines' => [], 'sections' => [$tier], 'date' => $date,
            'from' => $first['from'], 'to' => $last['to'], 'words' => array_sum(array_column($w, 'words')),
            'text' => substr($body, $first['from'], $last['to'] - $first['from']),
        ], JSON_UNESCAPED_UNICODE) . "\n");
        $counts['windows']++;
    }
};

$claimed = [];
if ($mode === 'full') {
    foreach (rows($enhanced) as [$id, $dto, $extras]) {
        if ($issues !== null && !preg_match($issues, $id)) { continue; }
        $segs = $extras['segments'] ?? [];
        foreach (anchorKeys($extras) as $k) { $claimed[$k] = true; }
        if ($noAds && ($extras['recordKind'] ?? null) === 'advertisement') {
            $counts['ads']++;
            continue;
        }
        // The headline segment first (kind `headline` since harvest 1979139). A record whose only
        // segment holds its whole text omits the copy; the text is then the record's ocrText.
        $headline = clean((string) ($segs[0]['text'] ?? $dto['title'] ?? ''));
        $body = count($segs) > 1
            ? clean(implode("\n", array_map(fn ($s) => (string) ($s['text'] ?? ''), array_slice($segs, 1))))
            : clean((string) ($dto['ocrText'] ?? ''));
        $emit($id, $headline, $body, $dto['date'] ?? null, 'full');
        $counts['stories']++;
    }
}
foreach (rows($basic) as [$id, $dto, $extras]) {
    if ($issues !== null && !preg_match($issues, $id)) { continue; }
    $keys = anchorKeys($extras);
    if ($keys !== [] && array_filter($keys, fn ($k) => isset($claimed[$k])) === $keys) {
        $counts['claimed']++;
        continue;
    }
    $emit($id, '', clean((string) ($dto['ocrText'] ?? $dto['description'] ?? '')), $dto['date'] ?? null, 'block');
    $counts['blocks']++;
}
fclose($fw);
fclose($fa);
fprintf(STDERR, "%s: %d stories (%d ad groups left out), %d loose blocks (%d claimed by stories), %d windows, %d empty\n",
    $mode, $counts['stories'], $counts['ads'], $counts['blocks'], $counts['claimed'], $counts['windows'], $counts['empty']);
