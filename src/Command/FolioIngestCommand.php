<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deprecated: replaced by folio:build (which shares the same FolioIngestService).
 * Kept only to intercept old invocations and point at the new command.
 */
#[AsCommand('folio:ingest', '[deprecated] Use folio:build instead.')]
final class FolioIngestCommand extends Command
{
    protected function configure(): void
    {
        // Accept the old surface so stale callers parse and reach the guidance below.
        $this->addArgument('dataset', InputArgument::OPTIONAL, 'Dataset key')
            ->addOption('all', null, InputOption::VALUE_NONE)
            ->addOption('provider', null, InputOption::VALUE_REQUIRED)
            ->addOption('core', null, InputOption::VALUE_REQUIRED)
            ->addOption('id-field', null, InputOption::VALUE_REQUIRED)
            ->addOption('label-field', null, InputOption::VALUE_REQUIRED)
            ->addOption('batch', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $target = (string) ($input->getArgument('dataset') ?? '');
        $selector = $target !== '' ? '--dataset=' . $target
            : ((string) ($input->getOption('provider') ?? '') !== '' ? '--provider=' . $input->getOption('provider')
            : ($input->getOption('all') ? '--all' : '--dataset=<provider/code>'));

        $io->error('folio:ingest has been removed. Use folio:build.');
        $io->writeln([
            '  Working folio only (rows + indexes + FTS + views, no archive):',
            sprintf('    <info>bin/console folio:build %s --no-archive</info>', $selector),
            '',
            '  Distributable archive (+ working copy):',
            sprintf('    <info>bin/console folio:build %s</info>', $selector),
            '',
        ]);

        return Command::FAILURE;
    }
}
