<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DataContracts\Vocabulary\TermSetBinding;
use Survos\FolioBundle\Entity\Term;

/**
 * Resolves a row's tags/keywords into per-term-set label+link data. Extracted from
 * FolioController::detail() (the chrome-free power-user viewer) so the FolioItem UX
 * component (the lean public detail page) can show the same tags without duplicating this
 * logic — same field->termset binding the extractor used to write the Term rows (single
 * source of truth: #[VocabTerm(termSet:true, sourceFields:…)] on MuseumVocab), so what's
 * resolved here matches what exists.
 */
final class RowTermsResolver
{
    // Keyed by the full "$folioCode:$setCode:$code" term id. resolve() runs once per rendered
    // item on pages that list many rows (e.g. the "Most Results" facet-value panel), and the
    // same term (e.g. a common genre) recurs across most of them -- seen live: 1000 identical
    // `find(Term::class, ...)` queries for one id on a single search page. $em->find() should
    // hit Doctrine's identity map on repeat calls, but each item's context() call can land on a
    // fresh/cleared EM (see FolioService::switch()), defeating it -- so cache here too.
    /** @var array<string, ?Term> */
    private array $termCache = [];

    /**
     * @param array<string,mixed> $dtoData
     * @param array<string,mixed> $extras
     * @return array<string,list<array{code:string,label:string,term:?Term}>>
     */
    public function resolve(EntityManagerInterface $em, string $folioCode, array $dtoData, array $extras): array
    {
        $terms = [];
        foreach (TermSetBinding::fields() as $setCode => $fields) {
            $labels = [];
            foreach ($fields as $field) {
                foreach ($this->termValues($dtoData[$field] ?? $extras[$field] ?? null) as $label) {
                    $labels[] = $label;
                }
            }
            // Dedupe by value (array_unique), NOT by key — a numeric-string label like "1886" used as
            // an array key would be coerced to an int and break termCode(string).
            foreach (array_unique($labels) as $label) {
                $code = $this->termCode($label);
                $termId = "$folioCode:$setCode:$code";
                $term = array_key_exists($termId, $this->termCache)
                    ? $this->termCache[$termId]
                    : $this->termCache[$termId] = $em->find(Term::class, $termId);
                $terms[$setCode][] = [
                    'code' => $code,
                    'label' => $label,
                    'term' => $term instanceof Term ? $term : null,
                ];
            }
        }

        return array_filter($terms);
    }

    /** @return list<string> */
    private function termValues(mixed $value): array
    {
        if (is_array($value)) {
            $values = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    foreach (['name', 'label', 'value', 'type'] as $key) {
                        if (isset($item[$key]) && is_scalar($item[$key])) {
                            $values[] = trim((string) $item[$key]);
                            break;
                        }
                    }
                    continue;
                }
                if (is_scalar($item)) {
                    $values[] = trim((string) $item);
                }
            }

            return array_values(array_unique(array_filter($values, 'strlen')));
        }

        return is_scalar($value) && trim((string) $value) !== '' ? [trim((string) $value)] : [];
    }

    private function termCode(string $label): string
    {
        $code = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label);
        $code = preg_replace('/[^a-z0-9]+/', '-', $code) ?? '';
        $code = trim($code, '-');

        return $code !== '' ? $code : hash('xxh128', $label);
    }
}
