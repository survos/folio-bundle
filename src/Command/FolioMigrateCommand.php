<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Service\FolioRegistry;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('folio:migrate', 'Create or update folio SQLite schemas.')]
final class FolioMigrateCommand extends Command
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioRegistry $registry,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('dataset', InputArgument::OPTIONAL, 'Dataset key (e.g. mus/cleveland)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Migrate all known datasets')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Migrate all datasets for a provider');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $datasets = $this->registry->datasets(
            datasetKey: (string) ($input->getArgument('dataset') ?? '') ?: null,
            provider: (string) ($input->getOption('provider') ?? '') ?: null,
            all: (bool) $input->getOption('all'),
        );

        if ($datasets === []) {
            $io->warning('No datasets found. Run data:scan-datasets first.');
            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($datasets as $dataset) {
            // Create from bootstrap if new; update schema in-place if existing.
            if (!is_file($this->folios->path($dataset->datasetKey))) {
                $this->folios->reset($dataset->datasetKey);
            }
            $ctx = $this->folios->context($dataset->datasetKey, true);

            // Update metadata fields the service doesn't know about.
            $folio = $ctx->em->find(Folio::class, $ctx->folioCode);
            $folio->label = $dataset->label;
            $folio->datasetKey = $dataset->datasetKey;
            $ctx->em->flush();

            $io->text(sprintf('  ✓ %s → %s', $dataset->datasetKey, $ctx->path));
            $count++;
        }

        $io->success(sprintf('%d folio(s) migrated.', $count));
        return Command::SUCCESS;
    }
}
