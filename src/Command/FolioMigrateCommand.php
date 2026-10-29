<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Service\FolioMigrator;
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
        private readonly FolioMigrator $migrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Existing folio SQLite file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (($file = $input->getOption('file')) === null) {
            throw new \InvalidArgumentException('Pass --file=<folio> to migrate one existing file.');
        }
        $result = $this->migrator->migrate($file);
        $io->success(sprintf('Folio migrated: %s (%d items)', $file, $result['items']));

        return Command::SUCCESS;
    }
}
