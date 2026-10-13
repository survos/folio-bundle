<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('folio:migrate', 'Migrate metadata in an explicitly selected folio file.')]
final class FolioMigrateCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('file', null, InputOption::VALUE_REQUIRED, 'Existing folio SQLite file');
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
            $io->success('Folio metadata converted: '.$file);

            return Command::SUCCESS;
        }

        throw new \InvalidArgumentException('Pass --file=<folio> to migrate one existing file.');
    }
}
