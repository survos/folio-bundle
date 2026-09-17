<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/**
 * A search snippet built from row text, for folios whose FTS table stores no content.
 *
 * Mirrors what `snippet(item_fts, 0, '[[[', ']]]', '...', N)` returns: a window of about N tokens
 * around the first matching term, matches wrapped in [[[ ]]]. Matching is case-insensitive on word
 * prefixes (the FTS query is prefix-quoted), with a stem-length fallback so porter-stemmed hits such as
 * "farmers" for "farming" still find a window. When nothing matches, the text's opening is returned.
 */
final class FolioSnippet
{
    /** Terms from a MATCH expression or a reader's query: words only, quotes, operators and `*` removed. */
    public static function terms(string $query): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $query, $m);
        $terms = array_filter(array_map(static fn (string $t): string => mb_strtolower($t), $m[0]),
            static fn (string $t): bool => mb_strlen($t) > 1 && !in_array($t, ['and', 'or', 'not', 'near'], true));

        return array_values(array_unique($terms));
    }

    public static function fromText(?string $text, string $query, int $tokens = 32): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
        if ($text === '') {
            return '';
        }
        $words = preg_split('/ /u', $text) ?: [];
        $terms = self::terms($query);
        $matches = [];
        foreach ($words as $i => $word) {
            $bare = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $word) ?? '');
            foreach ($terms as $term) {
                if ($bare !== '' && (str_starts_with($bare, $term) || self::sameStem($bare, $term))) {
                    $matches[$i] = true;
                    break;
                }
            }
        }
        $first = $matches === [] ? 0 : array_key_first($matches);
        $start = max(0, $first - intdiv($tokens, 3));
        $slice = array_slice($words, $start, $tokens, true);
        $out = [];
        foreach ($slice as $i => $word) {
            $out[] = isset($matches[$i]) ? '[[['.$word.']]]' : $word;
        }

        return ($start > 0 ? '...' : '').implode(' ', $out).($start + $tokens < count($words) ? '...' : '');
    }

    /** farming ~ farmers ~ farmed, but not Farmville: a shared root followed only by an inflection. */
    private static function sameStem(string $word, string $term): bool
    {
        $suffixes = '(?:s|es|ed|er|ers|ing|ings|ly|ies|ied|y|e)?';
        $root = preg_replace('/'.$suffixes.'$/u', '', $term) ?? $term;

        return mb_strlen($root) >= 4 && preg_match('/^'.preg_quote($root, '/').$suffixes.'$/u', $word) === 1;
    }
}
