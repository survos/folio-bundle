<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Message\IndexFolioRowsMessage;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand('folio:elastic:index', 'Index folios into the shared Elasticsearch search indexes (folio_item / folio_text), or verify their coverage.')]
final class FolioElasticIndexCommand
{
    public function __construct(
        private readonly FolioSearchIndex $index,
        private readonly FolioService $folios,
        private readonly ?MessageBusInterface $bus = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Folio codes, e.g. nara/coll_bho-whpo')] array $folioCodes = [],
        #[Option('Every published source folio under the data root (translations excluded)')] bool $all = false,
        #[Option('With --all: only this provider')] ?string $provider = null,
        #[Option('Only report coverage: SQLite rows vs ES docs for the current build; exits 1 on any gap')] bool $verify = false,
        #[Option('Only (re)index folios that do not verify')] bool $staleOnly = false,
        #[Option('Dispatch IndexFolioRowsMessage per folio instead of indexing here')] bool $queue = false,
        #[Option('Delete the folios from the indexes instead')] bool $remove = false,
    ): int {
        if (!$this->index->isConfigured()) {
            $io->error('Set ELASTICSEARCH_DSN, e.g. elasticsearch://127.0.0.1:9200');

            return Command::INVALID;
        }
        if ($queue && null === $this->bus) {
            $io->error('No message bus in this app; run without --queue.');

            return Command::INVALID;
        }
        if ($all) {
            $folioCodes = $this->publishedFolios($provider);
        }
        if ([] === $folioCodes) {
            $io->error('Name folio codes or pass --all.');

            return Command::INVALID;
        }

        $failed = 0;
        $tally = [];
        foreach ($folioCodes as $folioCode) {
            if ($remove) {
                $io->writeln(sprintf('%-44s %s doc(s) removed', $folioCode, number_format($this->index->remove($folioCode))));
                continue;
            }
            if ($verify || $staleOnly) {
                $check = $this->index->verify($folioCode);
                $tally[$check['status']] = ($tally[$check['status']] ?? 0) + 1;
                if ($verify) {
                    if ('ok' !== $check['status']) {
                        ++$failed;
                    }
                    $io->writeln(sprintf('%-44s %-11s item %s/%s  text %s/%s  stale %s  meta %s', $folioCode, $check['status'],
                        $check['indexed']['item'] ?? '?', $check['expected']['item'], $check['indexed']['text'] ?? '?', $check['expected']['text'],
                        $check['stale'] ?? '?', $check['meta'] ?? '-'));
                    continue;
                }
                if ('ok' === $check['status']) {
                    continue;
                }
            }
            if ($queue) {
                $this->bus->dispatch(new IndexFolioRowsMessage($folioCode));
                $io->writeln(sprintf('%-44s queued', $folioCode));
                continue;
            }
            $started = microtime(true);
            $progress = $io->createProgressBar();
            try {
                $result = $this->index->index($folioCode, static fn (int $rows) => $progress->setProgress($rows));
            } catch (\Throwable $e) {
                $progress->clear();
                ++$failed;
                $io->writeln(sprintf('<error>%-44s %s</error>', $folioCode, $e->getMessage()));
                continue;
            }
            $progress->clear();
            $io->writeln(sprintf('%-44s %s item + %s text, %s MB text, %s stale removed, verified, %.1f s', $folioCode,
                number_format($result['item']), number_format($result['text']), number_format($result['bytes'] / 1e6, 1),
                number_format($result['removed']), microtime(true) - $started));
        }
        if ([] !== $tally) {
            ksort($tally);
            $io->writeln(implode('  ', array_map(static fn (string $k, int $v): string => "$k: $v", array_keys($tally), $tally)));
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return list<string> */
    private function publishedFolios(?string $provider): array
    {
        $extension = $this->folios->fileExtension();
        $codes = [];
        foreach (glob($this->folios->rootPath().'/'.($provider ?? '*').'/*.'.$extension) ?: [] as $path) {
            $name = basename($path);
            // <dataset>.<locale>.folio is a translation of <dataset>.folio, not another set of rows.
            if (preg_match('/\.[a-z]{2}(?:-[A-Za-z]+)?\.'.preg_quote($extension, '/').'$/', $name) || str_starts_with($name, '_')) {
                continue;
            }
            $codes[] = basename(dirname($path)).'/'.substr($name, 0, -strlen($extension) - 1);
        }
        sort($codes);

        return $codes;
    }
}
