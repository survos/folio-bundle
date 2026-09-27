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

#[AsCommand('folio:elastic:index', 'Index folio rows into the shared Elasticsearch row index, for folios searched without FTS.')]
final class FolioElasticIndexCommand
{
    public function __construct(
        private readonly FolioElasticRowIndex $index,
        private readonly ?MessageBusInterface $bus = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Folio codes, e.g. nara/coll_bho-whpo')] array $folioCodes,
        #[Option('Dispatch IndexFolioRowsMessage per folio instead of indexing here')] bool $queue = false,
        #[Option('Delete the folios\' rows from the index instead')] bool $remove = false,
    ): int {
        if (!$this->index->isConfigured()) {
            $io->error('Set ELASTICSEARCH_DSN, e.g. elasticsearch://127.0.0.1:9200');

            return Command::INVALID;
        }
        if ($queue && $this->bus === null) {
            $io->error('No message bus in this app; run without --queue.');

            return Command::INVALID;
        }
        foreach ($folioCodes as $folioCode) {
            if ($remove) {
                $io->writeln(sprintf('%-40s %s row(s) removed', $folioCode, number_format($this->index->remove($folioCode))));
                continue;
            }
            if ($queue) {
                $this->bus->dispatch(new IndexFolioRowsMessage($folioCode));
                $io->writeln(sprintf('%-40s queued', $folioCode));
                continue;
            }
            $started = microtime(true);
            $progress = $io->createProgressBar();
            $result = $this->index->index($folioCode, static fn (int $rows) => $progress->setProgress($rows));
            $progress->clear();
            $io->writeln(sprintf('%-40s %s row(s), %s MB of text, %s stale removed, %.1f s', $folioCode,
                number_format($result['rows']), number_format($result['bytes'] / 1e6, 1),
                number_format($result['removed']), microtime(true) - $started));
        }

        return Command::SUCCESS;
    }
}
