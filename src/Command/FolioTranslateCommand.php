<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\DatasetBundle\Enum\Stage;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\DatasetBundle\Service\DatasetIntlService;
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-command version of the manual extract-terms -> push -> pull -> folio:build cycle this
 * repo's translation demo needed (mus/cazma: hr -> en via deepl, July 2026). Idempotent —
 * push/pull's own content-hash dedup (survos-lingua's Source/Target tuple) means re-running
 * this after a source dataset changes only translates what's actually new, and re-running it
 * with nothing changed is a fast no-op all the way through.
 *
 * Needs dataset-bundle + lingua-bundle; degrades to a clear error (not a crash) without them,
 * same pattern as FolioRegistry's optional dataset_entity_manager.
 */
#[AsCommand('folio:translate', 'Extract, push, pull, and build a localized folio in one step')]
final class FolioTranslateCommand
{
    public function __construct(
        private readonly FolioBuildCommand $buildCommand,
        private readonly ?DatasetIntlService $intl = null,
        private readonly ?DatasetInfoRepository $datasets = null,
        private readonly ?DataPaths $dataPaths = null,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('dataset key, e.g. mus/cazma')] string $dataset,
        #[Option('comma-separated target locales — defaults to the dataset\'s configured locale.targets')] ?string $targets = null,
        #[Option('preferred engine (libre, deepl, …) — defaults to the dataset\'s configured locale.preferredEngine')] ?string $engine = null,
        #[Option('max seconds to wait for translation to complete before building with whatever is available')] int $maxWait = 120,
        #[Option('seconds between pull attempts while waiting')] int $pollInterval = 10,
        #[Option('rebuild the folio even if it already exists')] bool $force = true,
    ): int {
        if (!$this->intl || !$this->datasets || !$this->dataPaths) {
            $io->error('folio:translate needs dataset-bundle + lingua-bundle (DatasetIntlService/DatasetInfoRepository/DataPaths) — not available in this app.');
            return Command::FAILURE;
        }

        $info = $this->datasets->find($dataset);
        if ($info === null) {
            $io->error(sprintf('Unknown dataset "%s". Run dataset:scan first.', $dataset));
            return Command::FAILURE;
        }

        $targetLocales = $targets !== null
            ? array_values(array_filter(array_map('trim', explode(',', $targets))))
            : $info->targetLocales;

        if ($targetLocales === []) {
            $io->error(sprintf(
                'No target locales for "%s" — pass --targets=en,… or set locale.targets in its harvester\'s writeMeta().',
                $dataset,
            ));
            return Command::INVALID;
        }

        $io->title(sprintf('Translating and building %s -> %s', $dataset, implode(', ', $targetLocales)));

        $io->section('extract-terms');
        ($this->intl)->extractTerms($io, $dataset);

        $io->section('push');
        ($this->intl)->push($io, $dataset, implode(',', $targetLocales), $engine, 200);

        $intlDir = $this->dataPaths->stageDir($dataset, Stage::Intl->value);
        $totalCodes = $this->countUniqueCodes($intlDir);

        $io->section('pull (polling until complete or --max-wait elapses)');
        $deadline = time() + $maxWait;
        while (true) {
            ($this->intl)->pull($io, $dataset, implode(',', $targetLocales), $engine);

            $counts = $this->translatedCounts($intlDir, $targetLocales);
            $complete = $totalCodes > 0 && !in_array(false, array_map(
                static fn (int $c): bool => $c >= $totalCodes,
                $counts,
            ), true);

            if ($complete) {
                $io->text('  all target locales fully translated.');
                break;
            }
            if (time() >= $deadline) {
                $io->warning('--max-wait elapsed with translation still incomplete — building anyway; folio:build falls back to source text for anything untranslated, so this is safe to re-run later once more is done.');
                break;
            }

            $io->text(sprintf('  not complete yet (%s), waiting %ds…', $this->formatCounts($counts, $totalCodes), $pollInterval));
            sleep($pollInterval);
        }

        foreach ($targetLocales as $locale) {
            $io->section(sprintf('folio:build --dataset=%s --locale=%s', $dataset, $locale));
            ($this->buildCommand)($io, dataset: $dataset, force: $force, locale: $locale);
        }

        $io->success(sprintf('Done: %s -> %s', $dataset, implode(', ', $targetLocales)));

        return Command::SUCCESS;
    }

    private function countUniqueCodes(string $intlDir): int
    {
        $codes = [];
        foreach (glob("$intlDir/phrases.*.jsonl") ?: [] as $file) {
            foreach (JsonlReader::open($file) as $row) {
                if (isset($row['code'])) {
                    $codes[(string) $row['code']] = true;
                }
            }
        }

        return count($codes);
    }

    /**
     * @param list<string> $locales
     * @return array<string, int>
     */
    private function translatedCounts(string $intlDir, array $locales): array
    {
        $counts = [];
        foreach ($locales as $locale) {
            $file = "$intlDir/tr.$locale.jsonl";
            $counts[$locale] = is_file($file) ? iterator_count(JsonlReader::open($file)) : 0;
        }

        return $counts;
    }

    /** @param array<string, int> $counts */
    private function formatCounts(array $counts, int $total): string
    {
        $parts = [];
        foreach ($counts as $locale => $count) {
            $parts[] = "$locale: $count/$total";
        }

        return implode(', ', $parts);
    }
}
