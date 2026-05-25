<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Service\FolioArchiveService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('folio:restore', 'Expand a folio archive and rebuild derived indexes.')]
final class FolioRestoreCommand extends Command
{
    public function __construct(private readonly FolioArchiveService $archives)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('archivePath', InputArgument::REQUIRED, 'Path to a .folio.sqlite.gz archive')
            ->addArgument('folioCode', InputArgument::REQUIRED, 'Folio code to restore, e.g. dc/05747667f')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace an existing folio database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->archives->restore(
            (string) $input->getArgument('archivePath'),
            (string) $input->getArgument('folioCode'),
            (bool) $input->getOption('force'),
        );

        $io->success(sprintf(
            'Restored %s to %s (%d rows indexed).',
            $result['archive'],
            $result['target'],
            $result['indexedRows'],
        ));

        return Command::SUCCESS;
    }
}
