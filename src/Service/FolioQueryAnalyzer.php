<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use voku\helper\StopWords;

final class FolioQueryAnalyzer
{
    private const FALLBACK_STOP_WORDS = [
        'the', 'and', 'or', 'for', 'with', 'what', 'which', 'that', 'this', 'from', 'about', 'show', 'find', 'are', 'was',
        'were', 'to', 'in', 'of', 'an', 'is',
    ];

    private const QUERY_INSTRUCTION_WORDS = [
        'build', 'gallery', 'group', 'groups', 'visitor', 'visitors', 'museum', 'item', 'items', 'collection', 'notable',
        'related', 'explain', 'connection', 'connections', 'interest', 'interesting', 'evidence', 'cite', 'cites', 'row',
        'rows', 'theme', 'themes', 'compact', 'using', 'each', 'fits', 'selected',
    ];

    /** @var array<string, array<string, true>> */
    private array $stopWordsByLocale = [];

    /**
     * Convert a natural-language question into conservative SQLite FTS5 prefix terms.
     *
     * @return list<string>
     */
    public function terms(string $question, string $locale = 'en'): array
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}_]{1,}/u', mb_strtolower($question), $matches);
        $stopWords = $this->stopWords($locale);

        $stopWords += array_fill_keys(self::QUERY_INSTRUCTION_WORDS, true);

        return array_values(array_unique(array_filter(
            $matches[0],
            static fn (string $term): bool => !isset($stopWords[$term]),
        )));
    }

    public function isStopWord(string $term, string $locale = 'en'): bool
    {
        return isset($this->stopWords($locale)[mb_strtolower($term)]);
    }

    /**
     * @return array<string, true>
     */
    private function stopWords(string $locale): array
    {
        if (isset($this->stopWordsByLocale[$locale])) {
            return $this->stopWordsByLocale[$locale];
        }

        if (class_exists(StopWords::class)) {
            $words = (new StopWords())->getStopWordsFromLanguage($locale);
        } else {
            $words = self::FALLBACK_STOP_WORDS;
        }

        return $this->stopWordsByLocale[$locale] = array_fill_keys(array_map('strval', $words), true);
    }
}
