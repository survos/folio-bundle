<?php

declare(strict_types=1);

/** Shared by the window spikes: word counting, cut scores, and the source-neutral packer. */

/**
 * Cut-point quality, stored per unit as 'cut' = how good it is to start a window AT this unit.
 * The packer knows nothing else about the source. 3 = preferred head (a question, a subhead),
 * 2 = acceptable (speaker change, paragraph), 1 = avoid (mid-speaker, sentence piece), 0 = never.
 */
const Q_QUESTION = 3, Q_SPEAKER = 2, Q_SAME = 1, Q_NEVER = 0;

function words(string $s): int
{
    // Pieces come from byte offsets; never let a malformed sequence make preg_split return false.
    return count(preg_split('/\s+/u', mb_scrub(trim($s), 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: []);
}

/**
 * Greedy packing inside each run (between recording cuts or headings). Once a window reaches the target it closes at the next
 * good cut point — preferring "before a question" so a question lands with its answer — and it is
 * forced closed before exceeding max. A tail shorter than min folds into the window before it.
 *
 * @return list<list<array>> windows as lists of units
 */
function packWindows(array $units, int $target, int $max, int $min): array
{
    $runs = [[]];
    foreach ($units as $u) {
        if ($u['kind'] === 'marker') {
            $runs[] = [];
            continue;
        }
        // A hard unit (a Markdown heading) is kept but always starts a fresh run.
        if (($u['hard'] ?? false) && $runs[array_key_last($runs)] !== []) {
            $runs[] = [];
        }
        $runs[array_key_last($runs)][] = $u;
    }

    $windows = [];
    foreach ($runs as $run) {
        $n = count($run);
        $s = 0;
        $first = count($windows);
        while ($s < $n) {
            $words = 0;
            $cut = $n;
            $best = null;
            for ($k = $s; $k < $n; $k++) {
                if ($k > $s && $words + $run[$k]['words'] > $max) {
                    $cut = $best ?? $k;
                    break;
                }
                $words += $run[$k]['words'];
                $next = $run[$k + 1] ?? null;
                if ($next === null || $words < $target) {
                    continue;
                }
                $q = $next['cut'];
                if ($q >= Q_QUESTION) {
                    $cut = $k + 1;
                    break;
                }
                // Remember the first speaker change past target; keep looking briefly for a question.
                if ($q === Q_SPEAKER && $best === null) {
                    $best = $k + 1;
                }
            }
            $windows[] = array_slice($run, $s, $cut - $s);
            $s = $cut;
        }
        // Fold a short tail back, never across a recording cut.
        $last = count($windows) - 1;
        if ($last > $first && array_sum(array_column($windows[$last], 'words')) < $min) {
            $windows[$last - 1] = array_merge($windows[$last - 1], array_pop($windows));
        }
    }

    return array_values(array_filter($windows));
}
