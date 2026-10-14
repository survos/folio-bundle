<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/**
 * Reduces a row's free-text rights to one facetable licence. Providers write the same thing as a
 * CC URL, a rightsstatements.org URI, "CC 0", "© Auckland Museum CC BY 4.0" or a paragraph; a
 * reader wants a dozen values it can filter on. The raw text stays on the row.
 */
final class RightsClassifier
{
    public const string CC0 = 'CC0';
    public const string PUBLIC_DOMAIN = 'Public domain';
    public const string NO_KNOWN_RESTRICTIONS = 'No known restrictions';
    public const string IN_COPYRIGHT = 'In copyright';
    public const string NOT_EVALUATED = 'Not evaluated';
    public const string OTHER = 'Other';

    /** The most specific statement wins: a URI over prose, a licence over a copyright notice. */
    public static function classify(mixed ...$statements): ?string
    {
        $texts = [];
        foreach ($statements as $statement) {
            foreach (is_array($statement) ? $statement : [$statement] as $value) {
                if (is_scalar($value) && '' !== trim((string) $value)) {
                    $texts[] = trim((string) $value);
                }
            }
        }
        if ([] === $texts) {
            return null;
        }
        foreach ($texts as $text) {
            if (null !== $licence = self::fromUri($text)) {
                return $licence;
            }
        }
        foreach ($texts as $text) {
            if (null !== $licence = self::fromCreativeCommons($text)) {
                return $licence;
            }
        }

        return self::fromProse(implode(' ', $texts));
    }

    private static function fromUri(string $text): ?string
    {
        if (preg_match('~creativecommons\.org/publicdomain/zero~i', $text)) {
            return self::CC0;
        }
        if (preg_match('~creativecommons\.org/publicdomain/mark~i', $text)) {
            return self::PUBLIC_DOMAIN;
        }
        if (preg_match('~creativecommons\.org/licenses/([a-z-]+)~i', $text, $m)) {
            return self::cc(explode('-', $m[1]));
        }
        if (preg_match('~rightsstatements\.org/(?:vocab|page)/([A-Za-z]+)(?:-([A-Z]+))?~', $text, $m)) {
            return match (strtoupper($m[1])) {
                'INC' => self::IN_COPYRIGHT,
                'NOC' => 'US' === ($m[2] ?? null) ? self::PUBLIC_DOMAIN : self::NO_KNOWN_RESTRICTIONS,
                'NKC' => self::NO_KNOWN_RESTRICTIONS,
                default => self::NOT_EVALUATED, // CNE, UND
            };
        }

        return null;
    }

    /** "CC 0", "CC-BY-NC_SA", "© Museum CC BY 4.0". */
    private static function fromCreativeCommons(string $text): ?string
    {
        if (preg_match('~\bcc[\s_-]*(?:0|zero)\b~i', $text)) {
            return self::CC0;
        }
        if (preg_match('~\bcc[\s_-]*by((?:[\s_-]*(?:nc|sa|nd)(?![a-z]))*)~i', $text, $m)) {
            preg_match_all('~nc|sa|nd~i', $m[1], $parts);

            return self::cc(['by', ...$parts[0]]);
        }

        return null;
    }

    private static function fromProse(string $text): string
    {
        return match (true) {
            (bool) preg_match('~public\s+domain~i', $text) => self::PUBLIC_DOMAIN,
            (bool) preg_match('~no\s+(?:known\s+)?(?:copyright|restrictions)|unrestricted~i', $text) => self::NO_KNOWN_RESTRICTIONS,
            (bool) preg_match('~not\s+(?:been\s+)?evaluated|undetermined|unknown|untraced~i', $text) => self::NOT_EVALUATED,
            (bool) preg_match('~copyright|all\s+rights\s+reserved|©|\(c\)|^RR-~i', $text) => self::IN_COPYRIGHT,
            default => self::OTHER,
        };
    }

    /** @param list<string> $parts e.g. ['by', 'nc', 'sa'] */
    private static function cc(array $parts): string
    {
        $parts = array_map(strtoupper(...), $parts);
        if (['ZERO'] === $parts) {
            return self::CC0;
        }
        // Canonical order: BY, then NC, then SA or ND.
        $order = ['BY' => 0, 'NC' => 1, 'SA' => 2, 'ND' => 2];
        $parts = array_values(array_unique(array_filter($parts, static fn (string $p): bool => isset($order[$p]))));
        usort($parts, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        return 'CC '.implode('-', $parts ?: ['BY']);
    }
}
