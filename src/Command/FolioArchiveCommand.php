<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Service\{FolioArchiveService,FolioService};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('folio:archive', 'Create a compressed folio archive without rebuildable indexes.')]
final class FolioArchiveCommand extends Command
{
    public function __construct(
        private readonly FolioArchiveService $archives,
        private readonly FolioService $folios,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('folioCode', InputArgument::REQUIRED, 'Folio code, e.g. dc/05747667f')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Archive path; defaults to the canonical folio-archive location, <root>/folio-archive/<provider>/<code>.folio.gz')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Archive a translated build, e.g. --locale=en snapshots <code>.en.folio to <code>.en.folio.gz');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $folioCode = (string) $input->getArgument('folioCode');
        $locale = is_string($input->getOption('locale')) && $input->getOption('locale') !== '' ? $input->getOption('locale') : null;
        // Default to the canonical archive path (same as folio:build --gz), so dataset:scan
        // and the archive API pick the snapshot up without a manual move.
        $archivePath = is_string($input->getOption('output'))
            ? $input->getOption('output')
            : $this->folios->archivePath($folioCode, createDirectory: true, locale: $locale);

        $result = $this->archives->archive($folioCode, $archivePath, $locale);

        $io->success(sprintf(
            'Archived %s to %s (%s → %s).',
            $result['source'],
            $result['archive'],
            $this->formatBytes($result['sourceBytes']),
            $this->formatBytes($result['archiveBytes']),
        ));

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        return $bytes < 1048576 ? sprintf('%.1f KiB', $bytes / 1024) : sprintf('%.1f MiB', $bytes / 1048576);
    }
}
