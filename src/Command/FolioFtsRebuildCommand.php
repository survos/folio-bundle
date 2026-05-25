<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Service\{FolioFtsIndexer,FolioService};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('folio:fts:rebuild', 'Rebuild the SQLite FTS5 index for a folio.')]
final class FolioFtsRebuildCommand extends Command
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioFtsIndexer $indexer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('folioCode', InputArgument::REQUIRED, 'Folio code, e.g. dc/05747667f')
            ->addOption('query', null, InputOption::VALUE_REQUIRED, 'Run a test MATCH query after rebuild')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows to show for --query', 5);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $folioCode = (string) $input->getArgument('folioCode');
        $ctx = $this->folios->context($folioCode);
        $result = $this->indexer->rebuild($ctx->path);

        $io->success(sprintf(
            'Rebuilt item_fts for %s: %d rows, %s indexed text.',
            $folioCode,
            $result['rows'],
            $this->formatBytes($result['bytes']),
        ));

        $query = $input->getOption('query');
        if (is_string($query) && trim($query) !== '') {
            $rows = $this->indexer->search($ctx->path, $query, (int) $input->getOption('limit'));
            $io->table(
                ['Core', 'Type', 'Row', 'Label', 'Score'],
                array_map(static fn (array $row): array => [
                    $row['coreId'] ?? '',
                    $row['dtoType'] ?? '',
                    $row['localId'] ?? '',
                    $row['label'] ?? '',
                    isset($row['score']) ? sprintf('%.4f', (float) $row['score']) : '',
                ], $rows),
            );
        }

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return sprintf('%.1f KiB', $bytes / 1024);
        }

        return sprintf('%.1f MiB', $bytes / 1048576);
    }
}
