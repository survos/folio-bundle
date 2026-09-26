<?php

declare(strict_types=1);

/**
 * Spike: merge born-digital article paragraphs (Markdown bodyText) into retrievable windows.
 *
 * Same packer as dialogue-windows.php; only the unit extraction differs. A window is always one
 * contiguous byte range of the article's bodyText, so its provenance is {rowId, from, to}.
 *
 *   php article-windows.php <article.jsonl> [idOrTitleSubstring] [--target=120] [--max=250] [--min=40] [--whole] [--json] [--stats]
 *   php article-windows.php --folio=<rappnews-digital.folio> [...]   roles from the folio's segments instead
 */

require getenv('HOME') . '/sites/lingua/vendor/autoload.php';
require __DIR__ . '/windows-lib.php';

use Vanderlee\Sentence\Sentence;

/**
 * Block roles. Headline, subhead and byline are different kinds of block, not body text that happens
 * to be short: only body blocks are packed into windows. Headline + subhead path ride along on every
 * window as fields; bylines, credits and signatures become metadata. The same vocabulary is what OCR
 * pages must produce (ALTO font lift → headline/subhead), so digital and scanned articles meet here.
 *
 *   headline   the row title (never in bodyText for this source)
 *   subhead    "### Building permits" (level 1-6, a hard boundary) or "**Jackson**" (level 7, minor)
 *   deck       italic note before the first body block: "*From staff and contributed reports*"
 *   byline     "By Helen Williams" (the row's creators carry the rest)
 *   signature  "*— Melissa Delcour*" closing a letter
 *   dateline   a bare date line: "January 19, 1961" in the history columns
 *   credit     "Rappahannock News Staff Photos/Jan Clatterbuck"
 *   aside      a short italic paragraph mid-article (pull quote or orphaned caption)
 *   body       everything else, including list items
 */
function role(string $text, bool $bodySeen, bool $nearEnd, bool $letter, ?string $prevRole): array
{
    $words = words($text);
    $bare = trim($text, "*_ \t");
    return match (true) {
        (bool) preg_match('/^(#{1,6})\s+(.*)$/s', $text, $m) => ['subhead', strlen($m[1]), trim($m[2])],
        // Emphasis does not make a byline a subhead: "**By Anita L. Sherman**".
        (bool) preg_match('/^By\s+\p{Lu}/u', $bare) && $words < 12 => ['byline', null, $bare],
        // A letter closes with a name, then often a place: "**Patty O’Brien**" / "Huntly".
        $prevRole === 'signature' && $words <= 4 => ['signature', null, $bare],
        $letter && $nearEnd && $words <= 8 && (bool) preg_match('/^\p{Lu}[\p{L}.’\'-]*(\s+\p{Lu}[\p{L}.’\'-]*){1,3}(,\s*[\p{L} ]+)?$/u', $bare) => ['signature', null, $bare],
        (bool) preg_match('/^(\*\*|__)([^*_\n]{1,80})\1:?$/u', $text, $m) => ['subhead', 7, trim($m[2])],
        (bool) preg_match('/^[*_]\s*[—–-]\s*(.{1,80})[*_]$/u', $text, $m) => ['signature', null, trim($m[1])],
        (bool) preg_match('/^By\s+\p{Lu}/u', $text) && $words < 12 => ['byline', null, $text],
        // Read a link's text, not its target: "[Fourth of July celebrations](/2011/07/…/photo-gallery/)" has a
        // slash only inside a URL and is a paragraph; "Staff Photos/Jan Clatterbuck" is a credit.
        (bool) preg_match('/\b(Photos?|Photograph(y|s)?)\b.*\/|^(Photo|Courtesy)\b/i', preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text)) && $words < 15 => ['credit', null, $text],
        (bool) preg_match('/^(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2},\s+\d{4}$/', $text) => ['dateline', null, $text],
        (bool) preg_match('/^[*_][^*_\n].*[^*_\n][*_]$/su', $text) => [$bodySeen ? 'aside' : 'deck', null, trim($text, '*_ ')],
        default => ['body', null, $text],
    };
}

/**
 * Role-typed blocks from the Markdown itself, by {@see role()}: [para, role, level, text, from, to].
 *
 * @return list<array{para: int, role: string, level: ?int, text: string, from: int, to: int}>
 */
function markdownBlocks(string $body, bool $letter = false): array
{
    $blocks = [];
    preg_match_all('/\S(?:.*?)(?=\n\s*\n|\z)/s', $body, $m, PREG_OFFSET_CAPTURE);
    $bodySeen = false;
    $prevRole = null;
    foreach ($m[0] as $n => [$text, $from]) {
        $text = rtrim($text);
        [$role, $level, $value] = role($text, $bodySeen, $n >= count($m[0]) - 2, $letter, $prevRole);
        $prevRole = $role;
        $bodySeen = $bodySeen || $role === 'body';
        $blocks[] = ['para' => $n, 'role' => $role, 'level' => $level, 'text' => $value, 'from' => $from, 'to' => $from + strlen($text)];
    }

    return $blocks;
}

/**
 * The same blocks, read from a folio's segments (harvest's digital-markdown segmentation) instead of
 * re-derived: kind/role name the block, a bodyText anchor gives its bytes. Headline and creators
 * segments cite their own fields and are not body blocks. Segments carry no subhead level, but the
 * anchor points at the raw source, so "###" there is a heading (hard boundary), "**" a minor one.
 *
 * @param list<array<string, mixed>> $segments
 * @return list<array{para: int, role: string, level: ?int, text: string, from: int, to: int}>
 */
function segmentBlocks(array $segments, string $body): array
{
    $blocks = [];
    usort($segments, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
    foreach ($segments as $s) {
        $anchor = null;
        foreach ($s['anchors'] ?? [] as $a) {
            if (($a['field'] ?? null) === 'bodyText') { $anchor = $a; break; }
        }
        if ($anchor === null) {
            continue; // headline (title), byline (creators)
        }
        $raw = substr($body, $anchor['from'], $anchor['to'] - $anchor['from']);
        $role = match ([$s['kind'], $s['role'] ?? null]) {
            ['paragraph', null] => 'body',
            ['byline', null] => 'byline',
            default => $s['role'] ?? $s['kind'],
        };
        $level = $role === 'subhead' ? (preg_match('/^(#{1,6})\s/', $raw, $h) ? strlen($h[1]) : 7) : null;
        $blocks[] = ['para' => count($blocks), 'role' => $role, 'level' => $level, 'text' => (string) ($s['text'] ?? ''),
            'from' => $anchor['from'], 'to' => $anchor['to']];
    }

    return $blocks;
}

/**
 * Packable body units from role-typed blocks, whichever source typed them.
 *
 * @return list<array> units with byte offsets into $body and the subhead path in force
 */
function blockUnits(array $blocks, string $body, int $max, int $target): array
{
    $units = [];
    $splitter = new Sentence();
    $path = [];          // level => subhead text
    $afterMinor = false; // the body block after a "**Jackson**" subhead is a preferred window head
    $hard = false;
    foreach ($blocks as ['para' => $n, 'role' => $role, 'level' => $level, 'text' => $value, 'from' => $from, 'to' => $to]) {
        if ($role === 'subhead') {
            $path = array_filter($path, fn ($l) => $l < $level, ARRAY_FILTER_USE_KEY) + [$level => $value];
            ksort($path);
            $hard = $hard || $level <= 6;
            $afterMinor = $level === 7;
            continue;
        }
        if ($role === 'byline') {
            // A byline starts the story proper and never sits inside a section: a factbox heading
            // before it ("### If you go") no longer applies, and the story is a fresh run.
            $path = [];
            $hard = true;
        }
        if ($role !== 'body') {
            continue;
        }
        $text = substr($body, $from, $to - $from);
        $base = ['kind' => 'para', 'para' => $n, 'speaker' => null, 'hard' => $hard, 'path' => array_values($path)];
        $cut = $afterMinor ? Q_QUESTION : Q_SPEAKER;
        $hard = $afterMinor = false;

        if (words($text) <= $max) {
            $units[] = $base + ['cut' => $cut, 'text' => $text, 'from' => $from, 'to' => $to, 'words' => words($text), 'piece' => null];
            continue;
        }
        $cursor = 0;
        $buf = null;
        $pieces = [];
        foreach ($splitter->split($text) as $sentence) {
            $sText = trim($sentence);
            if ($sText === '') {
                continue;
            }
            $at = $cursor < strlen($text) ? strpos($text, $sText, $cursor) : false;
            $at = $at === false ? min($cursor, strlen($text)) : $at;
            $cursor = min(strlen($text), $at + strlen($sText));
            if ($buf !== null && words(substr($text, $buf[0], $cursor - $buf[0])) > $target) {
                $pieces[] = $buf;
                $buf = null;
            }
            $buf = [$buf[0] ?? $at, $cursor];
        }
        if ($buf !== null) {
            $pieces[] = $buf;
        }
        foreach ($pieces as $i => [$a, $b]) {
            $pt = substr($text, $a, $b - $a);
            $units[] = ($i === 0 ? $base : ['hard' => false] + $base) + ['cut' => $i === 0 ? $cut : Q_SAME, 'text' => $pt,
                'from' => $from + $a, 'to' => $from + $b, 'words' => words($pt), 'piece' => [$i, count($pieces)]];
        }
    }

    return $units;
}

/**
 * Articles to window: the JSONL rows, or — with --folio — the folio's article rows, each carrying
 * its segments. Row ids are the article's own id (the folio's local id), so both sources line up.
 */
function articleSource(?string $file, ?string $folio): Generator
{
    if ($folio !== null) {
        $pdo = new PDO('sqlite:file:' . $folio . '?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach ($pdo->query("SELECT id, dto_data, extras FROM item WHERE core_id LIKE '%:article' ORDER BY id") as $r) {
            $dto = json_decode($r['dto_data'], true) ?? [];
            $extras = json_decode($r['extras'] ?? 'null', true) ?? [];
            yield ['id' => substr($r['id'], strrpos($r['id'], ':') + 1), 'bodyText' => $dto['bodyText'] ?? $extras['bodyText'] ?? '',
                'segments' => $extras['segments'] ?? []] + $dto + $extras;
        }
        return;
    }
    foreach (new SplFileObject($file) as $line) {
        if (trim($line) !== '') {
            yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }
    }
}

$opts = ['target' => 120, 'max' => 250, 'min' => 40];
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(\w+)(?:=(.*))?$/', $a, $mm)) {
        $opts[$mm[1]] = $mm[2] ?? true;
    } else {
        $args[] = $a;
    }
}
[$file, $filter] = $args + [null, null];
$folio = isset($opts['folio']) ? (string) $opts['folio'] : null;
if ($folio !== null) {
    [$file, $filter] = [null, $args[0] ?? null];
}
if ($file === null && $folio === null) {
    fwrite(STDERR, "usage: php article-windows.php <article.jsonl> | --folio=<folio> [idOrTitleSubstring] [--target --max --min --whole --json --stats]\n");
    exit(1);
}

$all = [];
$roles = [];
$articles = $empty = $single = $split = 0;
foreach (articleSource($file, $folio) as $a) {
    if ($filter !== null && !str_contains($a['id'], $filter) && stripos($a['title'] ?? '', $filter) === false) {
        continue;
    }
    $body = (string) ($a['bodyText'] ?? '');
    $articles++;
    $blocks = $folio !== null
        ? segmentBlocks($a['segments'] ?? [], $body)
        : markdownBlocks($body, str_starts_with((string) ($a['title'] ?? ''), 'Letter') || in_array('opinion/letters', $a['sections'] ?? [], true));
    $units = blockUnits($blocks, $body, (int) $opts['max'], (int) $opts['target']);
    foreach ($blocks as $b) {
        $roles[$b['role']] = ($roles[$b['role']] ?? 0) + 1;
    }
    $meta = [];
    foreach ($blocks as $b) {
        if (in_array($b['role'], ['byline', 'signature', 'credit', 'deck', 'dateline'], true)) {
            $meta[$b['role']][] = $b['text'];
        }
    }
    if ($units === []) {
        $empty++;
        continue;
    }
    $split += count(array_unique(array_column(array_filter($units, fn ($u) => $u['piece'] !== null), 'para')));
    // --whole: the no-windowing baseline, one document per article (all body blocks, roles still applied).
    $windows = ($opts['whole'] ?? false) ? [array_map(fn ($u) => ['path' => []] + $u, $units)]
        : packWindows($units, (int) $opts['target'], (int) $opts['max'], (int) $opts['min']);
    $single += count($windows) === 1 ? 1 : 0;
    foreach ($windows as $w) {
        $first = $w[0];
        $last = $w[array_key_last($w)];
        $all[] = [
            'id' => sprintf('%s~%d-%d', $a['id'], $first['from'], $last['to']),
            'rowId' => $a['id'],
            'headline' => $a['title'] ?? null,
            // The subhead path of the window's first block: ["Home and land transfers", "Jackson"].
            'subheads' => $first['path'],
            'bylines' => $a['creators'] ?? [],
            'meta' => $meta ?: null,
            'date' => $a['date'] ?? null,
            'sections' => $a['sections'] ?? [],
            'paras' => [$first['para'], $last['para']],
            'from' => $first['from'],
            'to' => $last['to'],
            'words' => array_sum(array_column($w, 'words')),
            'text' => substr($body, $first['from'], $last['to'] - $first['from']),
        ];
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
        printf("── %s  «%s»%s  ¶%d–%d  bytes %d–%d  [%d words]\n", substr($w['rowId'], 0, 8), mb_strimwidth((string) $w['headline'], 0, 50, '…'),
            $w['subheads'] ? ' › ' . implode(' › ', $w['subheads']) : '', $w['paras'][0], $w['paras'][1], $w['from'], $w['to'], $w['words']);
        echo '   ', str_replace("\n", "\n   ", wordwrap($w['text'], 110)), "\n\n";
    }
}
$sizes = array_column($all, 'words');
sort($sizes);
$pct = fn (float $p) => $sizes ? $sizes[(int) floor($p * (count($sizes) - 1))] : 0;
fprintf(STDERR, "\n%d articles → %d windows  (target %d, max %d, min %d)\n", $articles, count($all), $opts['target'], $opts['max'], $opts['min']);
fprintf(STDERR, "  words/window  min %d · p10 %d · median %d · p90 %d · max %d · under min %d\n",
    $pct(0), $pct(.1), $pct(.5), $pct(.9), $pct(1), count(array_filter($sizes, fn ($s) => $s < $opts['min'])));
arsort($roles);
fprintf(STDERR, "  block roles: %s\n", implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys($roles), $roles)));
fprintf(STDERR, "  %d empty · %d fit in one window · %d paragraphs longer than max split by sentence\n", $empty, $single, $split);
