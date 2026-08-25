<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\FolioBundle\Event\FolioInvalidatedEvent;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Walk every folio file on disk, open each one, and report what is wrong with the ones that are.
 *
 * Deliberately FILESYSTEM-driven, which is the whole difference from `folio:migrate --all`. That
 * command iterates the dataset registry and asks where each dataset's folio should be, so it can
 * only ever see files it already expects — it is structurally incapable of noticing a file that
 * should not exist, and it silently skips locale variants (`<code>.<locale>.folio`), because the
 * registry has no row under the locale-suffixed key. This walks the directory instead, so both
 * classes of problem become visible.
 *
 * Opening a folio migrates it in place as a side effect (FolioService::context() ->
 * FolioSchemaManager::update()), so a full pass is also how you drain a migration backlog after a
 * schema change, sequentially and on your own terms, instead of leaving thousands of folios to
 * migrate one-at-a-time under live web traffic.
 *
 * Sequential on purpose: these are SQLite files sharing one disk, and the migration takes a write
 * lock on each. Parallelism would buy little and would reintroduce exactly the contention this
 * command exists to clear.
 *
 * Without --fix nothing is destroyed and no event fires: it reports, and it migrates (which is
 * idempotent self-healing, not a change of intent). --fix does two separable things — it deletes
 * the files that are provably garbage, and it announces EVERY invalid folio via
 * {@see FolioInvalidatedEvent} so the app can decide what a rebuild means.
 *
 * Deletion is deliberately narrower than invalidity. Only two cases are ever removed: a 0-byte
 * file, which SQLite could not have written, and an orphan with no dataset behind it. A folio with
 * 0 rows is invalid but NOT deleted, because it is usually an honest reflection of an empty
 * upstream stage rather than a damaged file -- on 2026-08-25, 474 of 477 zero-row folios were euro
 * datasets whose norm/ never produced a per.jsonl, so deleting them would have 404'd ~474 live
 * pages while changing nothing about the cause. Report it, emit the event, let the pipeline fix
 * the input.
 */
final class FolioValidateCommand
{
    /** SQLite writes this 16-byte magic at offset 0 of every database it creates, empty or not. */
    private const SQLITE_MAGIC = "SQLite format 3\0";

    /** Sidecars that belong to a folio and must go with it when it is deleted. */
    private const SIDECARS = ['-wal', '-shm', '-journal', '.lock'];

    public function __construct(
        private readonly FolioService $folios,
        private readonly EventDispatcherInterface $dispatcher,
        /**
         * Optional exactly as in {@see \Survos\FolioBundle\Service\FolioRegistry}: a bare app that
         * only pulls and displays folios has no dataset registry, and must still be able to run the
         * open/migrate half of this command. Orphan detection is skipped when it is null rather
         * than guessed at — see validate()'s note on why that distinction matters before deleting.
         */
        #[Target('doctrine.orm.dataset_entity_manager')]
        private readonly ?EntityManagerInterface $datasetEntityManager = null,
    ) {
    }

    #[AsCommand('folio:validate', 'Open every folio on disk (migrating stale ones) and report or remove the broken ones.')]
    public function __invoke(
        SymfonyStyle $io,
        #[Option('Restrict to one provider directory, e.g. euro')]
        ?string $provider = null,
        #[Option('Act on the findings: delete removable files and announce every invalid folio via FolioInvalidatedEvent. Off by default, so a plain run is read-only.')]
        bool $fix = false,
        #[Option('Stop after this many files; 0 means every one')]
        int $limit = 0,
    ): int {
        $root = $this->folios->rootPath();
        if (!is_dir($root)) {
            $io->error(sprintf('Folio root "%s" does not exist.', $root));

            return Command::FAILURE;
        }

        // <root>/<provider>/<code>[.<locale>].<ext> — one directory level, which also means the
        // bootstrap folio (<root>/_bootstrap.<ext>, no provider dir) is skipped for free.
        $files = glob(sprintf('%s/%s/*.%s', $root, $provider ?? '*', $this->folios->fileExtension())) ?: [];
        sort($files);
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }

        if ($files === []) {
            $io->warning(sprintf('No folio files under %s.', $root));

            return Command::SUCCESS;
        }

        $known = $this->knownDatasetKeys();
        if ($known === null) {
            $io->note('No dataset registry available — orphan detection skipped.');
        }

        $io->progressStart(count($files));
        $problems = [];
        $counts = ['ok' => 0, 'migrated' => 0];
        $removable = [];
        $invalid = [];

        foreach ($files as $file) {
            $result = $this->validate($file, $known);
            $counts[$result['status']] = ($counts[$result['status']] ?? 0) + 1;

            if (!in_array($result['status'], ['ok', 'migrated'], true)) {
                $problems[] = [$result['status'], $this->relative($file, $root), $result['detail']];
                $invalid[] = [$file, $result];
                if ($result['removable']) {
                    $removable[] = $file;
                }
            }
            $io->progressAdvance();
        }
        $io->progressFinish();

        if ($problems !== []) {
            $io->table(['status', 'file', 'detail'], $problems);
        }

        $deleted = [];
        if ($removable !== [] && $fix) {
            foreach ($removable as $file) {
                if ($this->remove($file)) {
                    $deleted[$file] = true;
                }
            }
            $io->success(sprintf('Removed %d file(s).', count($deleted)));
        } elseif ($removable !== []) {
            $io->warning(sprintf(
                '%d file(s) can be removed. Re-run with --fix to delete them.',
                count($removable),
            ));
        }

        // Announce every invalid folio, deleted or not: a 0-row folio is just as much a "this
        // dataset needs to go back through the pipeline" signal as a file we removed, and the app
        // is the only thing that knows which stage that means. Dispatched after the deletions so
        // $deleted is accurate for listeners that care.
        if ($fix && $invalid !== []) {
            foreach ($invalid as [$file, $result]) {
                [$folioCode, $locale] = $this->parse($file);
                $this->dispatcher->dispatch(new FolioInvalidatedEvent(
                    datasetKey: $folioCode,
                    reason: $result['status'],
                    detail: $result['detail'],
                    path: $file,
                    locale: $locale,
                    deleted: isset($deleted[$file]),
                ));
            }
            $io->note(sprintf('Announced %d invalid folio(s) via FolioInvalidatedEvent.', count($invalid)));
        } elseif ($invalid !== []) {
            $io->note(sprintf(
                '%d invalid folio(s) found. Re-run with --fix to announce them via FolioInvalidatedEvent.',
                count($invalid),
            ));
        }

        $io->writeln($this->summary($counts, count($files)));

        // A migration backlog or a deleted orphan is a successful run, not a failure; only a folio
        // that could not be opened at all is worth a non-zero exit for CI/cron.
        return ($counts['error'] ?? 0) > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  array<string, true>|null $known dataset keys, or null when there is no registry
     * @return array{status: string, detail: string, removable: bool}
     */
    private function validate(string $file, ?array $known): array
    {
        $size = (int) filesize($file);

        // Zero bytes is unambiguous: SQLite writes a 100-byte header the moment it creates a
        // database, so even a completely empty one is never 0 bytes. Nothing can be recovered
        // from this and nothing can open it, so it is safe to delete outright.
        if ($size === 0) {
            return ['status' => 'empty', 'detail' => '0 bytes — never a SQLite database', 'removable' => true];
        }

        if (($handle = @fopen($file, 'rb')) !== false) {
            $magic = (string) fread($handle, 16);
            fclose($handle);
            if ($magic !== self::SQLITE_MAGIC) {
                // NOT removable: unlike a 0-byte file this holds bytes we cannot account for — a
                // truncated download, a wrong-format upload, something half-written. Report it and
                // let a human decide; silently deleting data we failed to identify is not a fix.
                return ['status' => 'not-sqlite', 'detail' => sprintf('%s, no SQLite header', $this->bytes($size)), 'removable' => false];
            }
        }

        [$folioCode, $locale] = $this->parse($file);

        if ($known !== null && !$this->isKnown($folioCode, $locale, $known)) {
            return [
                'status' => 'orphan',
                'detail' => sprintf('no dataset "%s" in the registry', $folioCode),
                'removable' => true,
            ];
        }

        $before = $this->userVersion($file);

        try {
            $ctx = $this->folios->context($folioCode, locale: $locale);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'detail' => $e->getMessage(), 'removable' => false];
        }

        if (!$this->folios->isInflated($ctx->path)) {
            // The inflate step creates item_fts last, so its absence means the build died partway.
            // The file is real either way, so neither case is ever a delete candidate — but they
            // want different remedies, and the row count is what separates them. Counted only on
            // this branch, so a healthy folio never pays for a scan it does not need.
            $rows = (int) $ctx->em->getConnection()->fetchOne('SELECT COUNT(*) FROM item');

            return $rows > 0
                // Rows landed but the FTS build did not. Search is degraded on an otherwise fine
                // folio, and folio:fts:rebuild fixes it in place — a full rebuild is overkill here.
                ? ['status' => 'no-fts', 'detail' => sprintf('%s rows, no FTS — run folio:fts:rebuild', number_format($rows)), 'removable' => false]
                // No rows at all: ingest produced nothing, so there is nothing to index and the
                // folio has to be built again from the normalized JSONL.
                : ['status' => 'empty-build', 'detail' => '0 rows — rebuild from JSONL', 'removable' => false];
        }

        $after = (int) $ctx->em->getConnection()->fetchOne('PRAGMA user_version');

        return $before !== $after
            ? ['status' => 'migrated', 'detail' => sprintf('%d → %d', $before, $after), 'removable' => false]
            : ['status' => 'ok', 'detail' => '', 'removable' => false];
    }

    /**
     * Split `<root>/<provider>/<code>[.<locale>].<ext>` into a dataset key and an optional locale.
     *
     * The locale is a guess from the filename — there is nothing else to go on, since a built
     * folio's locale lives in the folio server's API response, not in the file or its name. A code
     * whose last dot-segment happens to look like a language tag (`foo.de`) parses as a locale
     * variant of `foo`. That guess is never load-bearing on its own: isKnown() accepts EITHER
     * reading, so a misparse cannot turn a real folio into an orphan.
     *
     * @return array{0: string, 1: string|null}
     */
    private function parse(string $file): array
    {
        $provider = basename(dirname($file));
        $base = basename($file, '.' . $this->folios->fileExtension());

        if (preg_match('/^(.+)\.([a-z]{2}(?:_[A-Z]{2})?)$/', $base, $m) === 1) {
            return [$provider . '/' . $m[1], $m[2]];
        }

        return [$provider . '/' . $base, null];
    }

    /**
     * True when either reading of the filename names a real dataset: the locale-stripped code
     * (`fpeu/italy` for `italy.es.folio`) or the literal basename (`fpeu/italy.es`, for a dataset
     * whose code genuinely ends in something locale-shaped). Deleting a real folio is far worse
     * than leaving one orphan in the report, so ambiguity resolves to "keep".
     *
     * @param array<string, true> $known
     */
    private function isKnown(string $folioCode, ?string $locale, array $known): bool
    {
        if (isset($known[$folioCode])) {
            return true;
        }

        return $locale !== null && isset($known[$folioCode . '.' . $locale]);
    }

    /** @return array<string, true>|null */
    private function knownDatasetKeys(): ?array
    {
        if ($this->datasetEntityManager === null) {
            return null;
        }

        $keys = [];
        foreach ($this->datasetEntityManager->getConnection()->iterateColumn(
            sprintf('SELECT dataset_key FROM %s', $this->datasetEntityManager->getClassMetadata(DatasetInfo::class)->getTableName()),
        ) as $key) {
            $keys[(string) $key] = true;
        }

        return $keys;
    }

    /** PRAGMA user_version read through a standalone handle, so the shared connection is untouched. */
    private function userVersion(string $file): int
    {
        try {
            return (int) (new \PDO('sqlite:' . $file))->query('PRAGMA user_version')->fetchColumn();
        } catch (\Throwable) {
            return -1;
        }
    }

    private function remove(string $file): bool
    {
        foreach (self::SIDECARS as $suffix) {
            if (is_file($file . $suffix)) {
                @unlink($file . $suffix);
            }
        }

        return @unlink($file);
    }

    private function relative(string $file, string $root): string
    {
        return str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    }

    private function bytes(int $size): string
    {
        return $size < 1024 ? $size . ' B' : sprintf('%.1f MB', $size / 1048576);
    }

    /** @param array<string, int> $counts */
    private function summary(array $counts, int $total): string
    {
        ksort($counts);
        $parts = [];
        foreach ($counts as $status => $n) {
            $parts[] = sprintf('%s: %d', $status, $n);
        }

        return sprintf('<info>%d folio(s)</info> — %s', $total, implode(', ', $parts));
    }
}
