<?php

declare(strict_types=1);

/**
 * Spike: merge dialogue turns (page.dialogue) into retrievable windows.
 *
 * Nothing here is wired into the bundle. It reads a folio's page.dialogue directly and prints the
 * windows it would index, so window size can be judged on real transcripts before any entity exists.
 * See ../segments-and-transcript-search.md.
 *
 *   php dialogue-windows.php <folio> [pageIdLike] [--target=120] [--max=250] [--min=40] [--overlap=0] [--json] [--stats]
 *
 * Uses vanderlee/php-sentence from ~/sites/lingua only to split turns that are too long on their own.
 */

require getenv('HOME') . '/sites/lingua/vendor/autoload.php';

use Vanderlee\Sentence\Sentence;

require __DIR__ . '/windows-lib.php';

const BOILERPLATE = [
    '/^Washington, D\.C\., \d{4}$/',
    '/^The Library of Congress makes digitized historical materials/',
    '/^This transcription is intended to have an accuracy rate/',
];
// Recording-structure markers: disc numbers and cuts. A window never crosses one.
const MARKER = '/^(AFS \S+|Cut [A-Z]\d+[a-z]?)\.?$/';
// "Mike Fox [?]: text" — a speaker label the normalizer left inline.
const INLINE_SPEAKER = '/^([A-Z][\p{L}.\'’-]*(?: [A-Z][\p{L}.\'’-]*){0,3})( \[\?\])?:\s+/u';

/**
 * Turn raw dialogue into units. A unit is a whole turn, or a sentence-aligned piece of a long one.
 *
 * @return array{units: list<array>, index: list<array>, dropped: array<string,int>}
 */
function toUnits(array $dialogue, int $maxWords, int $targetWords): array
{
    $units = $index = [];
    $dropped = ['boilerplate' => 0, 'header' => 0];
    $seenSpeaker = false;
    $splitter = new Sentence();

    foreach ($dialogue as $i => $turn) {
        $text = trim((string) ($turn['text'] ?? ''));
        $speaker = $turn['speaker'] ?? null;
        $startMs = $turn['startMs'] ?? null;
        $endMs = $turn['endMs'] ?? null;
        $uncertain = false;
        if ($text === '') {
            continue;
        }
        if ($speaker === null) {
            foreach (BOILERPLATE as $re) {
                if (preg_match($re, $text)) {
                    $dropped['boilerplate']++;
                    continue 2;
                }
            }
            if (preg_match(MARKER, $text)) {
                $units[] = ['kind' => 'marker', 'turn' => $i, 'text' => $text];
                continue;
            }
            if (preg_match(INLINE_SPEAKER, $text, $m)) {
                $speaker = $m[1];
                $uncertain = $m[2] !== '';
                $text = substr($text, strlen($m[0]));
            } elseif (!$seenSpeaker) {
                // Front matter before anyone speaks: a timed line is a curated index entry (COVID
                // transcripts carry a synopsis per time range), anything else is a title block.
                if ($startMs !== null) {
                    $index[] = ['turn' => $i, 'text' => $text, 'startMs' => $startMs, 'endMs' => $endMs];
                } else {
                    $dropped['header']++;
                }
                continue;
            }
        }
        $seenSpeaker = $seenSpeaker || $speaker !== null;
        $base = ['kind' => 'speech', 'turn' => $i, 'speaker' => $speaker, 'uncertain' => $uncertain,
            'startMs' => $startMs, 'endMs' => $endMs, 'question' => str_ends_with(rtrim($text, ' "”\''), '?')];

        if (words($text) <= $maxWords) {
            $units[] = $base + ['text' => $text, 'piece' => null, 'words' => words($text)];
            continue;
        }
        // A monologue longer than a window: pack its sentences into pieces near the target size,
        // keeping byte offsets into the turn so a hit can still be highlighted in place.
        $cursor = 0;
        $pieces = [];
        $buf = null;
        foreach ($splitter->split($text) as $sentence) {
            $s = trim($sentence);
            if ($s === '') {
                continue;
            }
            $at = strpos($text, $s, $cursor);
            $at = $at === false ? $cursor : $at;
            $cursor = $at + strlen($s);
            if ($buf !== null && words(substr($text, $buf[0], $cursor - $buf[0])) > $targetWords) {
                $pieces[] = $buf;
                $buf = null;
            }
            $buf = [$buf[0] ?? $at, $cursor];
        }
        if ($buf !== null) {
            $pieces[] = $buf;
        }
        foreach ($pieces as $n => [$from, $to]) {
            $pieceText = substr($text, $from, $to - $from);
            $units[] = ['question' => false] + $base + ['text' => $pieceText, 'piece' => [$n, count($pieces), $from, $to], 'words' => words($pieceText)];
        }
    }

    // Score each boundary. A backchannel ("Yeah.", "I know.") answers the turn before it and never heads a window.
    $prev = null;
    foreach ($units as &$u) {
        if ($u['kind'] === 'speech') {
            $backchannel = !$u['question'] && $u['words'] <= 3;
            $u['cut'] = $prev === null || $prev['speaker'] === $u['speaker'] || $backchannel ? Q_SAME
                : ($u['question'] ? Q_QUESTION : Q_SPEAKER);
            $prev = $u;
        }
    }
    unset($u);

    return ['units' => $units, 'index' => $index, 'dropped' => $dropped];
}

function toWindow(string $pageId, array $w, ?string $context): array
{
    $firstU = $w[0];
    $lastU = $w[array_key_last($w)];
    $ref = static fn (array $u): string => 't' . $u['turn'] . ($u['piece'] ? '.' . ($u['piece'][0] + 1) : '');
    $lines = [];
    foreach ($w as $j => $u) {
        $cont = $j > 0 && $w[$j - 1]['speaker'] === $u['speaker'] && $w[$j - 1]['turn'] === $u['turn'];
        $lines[] = $cont ? $u['text'] : sprintf('%s: %s', ($u['speaker'] ?? '(unattributed)') . ($u['uncertain'] ? ' [?]' : ''), $u['text']);
    }
    $starts = array_filter(array_column($w, 'startMs'), 'is_int');
    $ends = array_filter(array_column($w, 'endMs'), 'is_int');

    return [
        'id' => $pageId . '~' . $ref($firstU) . ($firstU === $lastU ? '' : '-' . $ref($lastU)),
        'pageId' => $pageId,
        'turns' => [$firstU['turn'], $lastU['turn']],
        // Present only when the window starts or ends mid-turn: [turn, byteFrom, byteTo].
        'firstPiece' => $firstU['piece'] ? [$firstU['turn'], $firstU['piece'][2], $firstU['piece'][3]] : null,
        'lastPiece' => $lastU['piece'] ? [$lastU['turn'], $lastU['piece'][2], $lastU['piece'][3]] : null,
        'speakers' => array_values(array_unique(array_filter(array_column($w, 'speaker')))),
        'startMs' => $starts ? min($starts) : null,
        'endMs' => $ends ? max($ends) : null,
        'words' => array_sum(array_column($w, 'words')),
        'context' => $context,
        'text' => implode("\n", $lines),
    ];
}

// ---------------------------------------------------------------------------------------------------

$opts = ['target' => 120, 'max' => 250, 'min' => 40, 'overlap' => 0];
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(\w+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        $args[] = $a;
    }
}
[$folio, $like] = $args + [null, '%'];
if ($folio === null) {
    fwrite(STDERR, "usage: php dialogue-windows.php <folio> [pageIdLike] [--target=120 --max=250 --min=40 --overlap=0 --json --stats]\n");
    exit(1);
}

$pdo = new PDO('sqlite:file:' . $folio . '?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$stmt = $pdo->prepare('SELECT id, dialogue FROM page WHERE dialogue IS NOT NULL AND id LIKE ? ORDER BY id');
$stmt->execute([$like]);

$all = [];
$totals = ['pages' => 0, 'turns' => 0, 'index' => 0, 'boilerplate' => 0, 'header' => 0, 'splitTurns' => 0];
foreach ($stmt as $row) {
    $dialogue = json_decode($row['dialogue'], true, flags: JSON_THROW_ON_ERROR);
    $u = toUnits($dialogue, (int) $opts['max'], (int) $opts['target']);
    $totals['pages']++;
    $totals['turns'] += count($dialogue);
    $totals['index'] += count($u['index']);
    $totals['boilerplate'] += $u['dropped']['boilerplate'];
    $totals['header'] += $u['dropped']['header'];
    $totals['splitTurns'] += count(array_unique(array_column(array_filter($u['units'], fn ($x) => ($x['piece'] ?? null) !== null), 'turn')));

    $prev = null;
    foreach (packWindows($u['units'], (int) $opts['target'], (int) $opts['max'], (int) $opts['min']) as $w) {
        $context = null;
        if ($prev !== null && (int) $opts['overlap'] > 0) {
            $tail = array_slice($prev, -(int) $opts['overlap']);
            $context = implode("\n", array_map(fn ($t) => ($t['speaker'] ?? '(unattributed)') . ': ' . $t['text'], $tail));
        }
        $all[] = toWindow($row['id'], $w, $context);
        $prev = $w;
    }
}

if ($opts['json'] ?? false) {
    foreach ($all as $w) {
        echo json_encode($w, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    }
    exit(0);
}

if (!($opts['stats'] ?? false)) {
    foreach ($all as $w) {
        $time = $w['startMs'] !== null ? sprintf(' @%s–%s', gmdate('G:i:s', intdiv($w['startMs'], 1000)), $w['endMs'] !== null ? gmdate('G:i:s', intdiv($w['endMs'], 1000)) : '?') : '';
        printf("── %s  [%d words, %s]%s\n", substr($w['id'], strrpos($w['id'], ':') + 1), $w['words'], implode(' / ', $w['speakers']) ?: '—', $time);
        if ($w['context']) {
            echo '   ┆ ', str_replace("\n", "\n   ┆ ", mb_strimwidth($w['context'], 0, 160, '…')), "\n";
        }
        echo '   ', str_replace("\n", "\n   ", wordwrap($w['text'], 110)), "\n\n";
    }
}

$sizes = array_column($all, 'words');
sort($sizes);
$pct = fn (float $p) => $sizes ? $sizes[(int) floor($p * (count($sizes) - 1))] : 0;
$bySpeakers = array_count_values(array_map(fn ($w) => min(count($w['speakers']), 3), $all));
ksort($bySpeakers);
fprintf(STDERR, "\n%d pages, %d turns → %d windows  (target %d, max %d, min %d, overlap %d)\n",
    $totals['pages'], $totals['turns'], count($all), $opts['target'], $opts['max'], $opts['min'], $opts['overlap']);
fprintf(STDERR, "  words/window  min %d · p10 %d · median %d · p90 %d · max %d\n", $pct(0), $pct(.1), $pct(.5), $pct(.9), $pct(1));
fprintf(STDERR, "  under min: %d · speakers/window: %s\n", count(array_filter($sizes, fn ($s) => $s < $opts['min'])),
    implode(', ', array_map(fn ($k, $v) => ($k === 3 ? '3+' : $k) . "→$v", array_keys($bySpeakers), $bySpeakers)));
fprintf(STDERR, "  set aside: %d boilerplate, %d header, %d index entries · %d long turns split by sentence\n",
    $totals['boilerplate'], $totals['header'], $totals['index'], $totals['splitTurns']);
