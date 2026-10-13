<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Dto;

/** Files and metadata selected by the caller, independent of any dataset registry or pipeline. */
final readonly class FolioBuildInput
{
    /**
     * @param array<string,string> $sources      Row core => JSONL file
     * @param array<string,mixed>  $metadata     Folio metadata, including label
     * @param array<string,string> $files        Optional page, termSet, term, linkType, link, claims, claimRuns, phrases files
     * @param array<string,string> $translations Target locale => translation JSONL file
     */
    public function __construct(
        public string $code,
        public array $sources,
        public array $metadata,
        public string $sourceLocale,
        public ?string $locale = null,
        public array $files = [],
        public array $translations = [],
        public ?string $reviewedTranslationFile = null,
    ) {
        if ('' === $sourceLocale || '' === $locale) {
            throw new \InvalidArgumentException('Use a non-empty source locale and null for an untranslated build.');
        }
        if ('' === $code || [] === $sources) {
            throw new \InvalidArgumentException('A folio build requires a code and explicit source files.');
        }
        foreach ([...array_values($sources), ...array_values($files), ...array_values($translations), ...(null !== $reviewedTranslationFile ? [$reviewedTranslationFile] : [])] as $file) {
            if (!is_file($file) || !is_readable($file)) {
                throw new \InvalidArgumentException('Folio input file is not readable: '.$file);
            }
        }
    }

    public function file(string $name): ?string
    {
        return $this->files[$name] ?? null;
    }
}
