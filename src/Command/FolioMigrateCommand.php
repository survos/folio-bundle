<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Service\FolioArchivePreparer;
use Survos\FolioBundle\Service\FolioFtsIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('folio:migrate', 'Migrate metadata in an explicitly selected folio file.')]
final class FolioMigrateCommand extends Command
{
    public function __construct(
        private readonly FolioArchivePreparer $preparer,
        private readonly FolioFtsIndexer $indexer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Existing folio SQLite file');
        $this->addOption('force-index', null, InputOption::VALUE_NONE, 'Rebuild the full-text index even for a folio over the row limit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (($file = $input->getOption('file')) !== null) {
            if (!is_file($file)) {
                throw new \InvalidArgumentException('Folio file not found: '.$file);
            }
            $pdo = new \PDO('sqlite:'.$file, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='folio'")->fetchColumn()) {
                throw new \InvalidArgumentException('Not a folio: '.$file);
            }
            (new \Survos\Folio\PropertyStore($pdo))->migrate();
            unset($pdo);
            // Rows keep their data; what a newer contract changes is how the folio describes it. Re-derive
            // the schema (facet/filter flags come from the DTO attributes), the views and docs, then the
            // browse indexes and facet counts, exactly as a build ends. No rows are rewritten.
            $this->preparer->prepare($file, ['migrated' => true]);
            $result = $this->indexer->rebuild($file, (bool) $input->getOption('force-index'));
            $io->success(sprintf('Folio migrated: %s (%d items%s)', $file, $result['items'] ?? 0, null !== ($result['skipped'] ?? null) ? ', full-text index skipped' : ''));

            return Command::SUCCESS;
        }

        throw new \InvalidArgumentException('Pass --file=<folio> to migrate one existing file.');
    }
}
