<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Service\{FolioArchiveService,FolioService};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand('folio:pull', 'Download folio archives from Hugging Face and inflate')]
final class FolioPullCommand
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioArchiveService $archiveService,
    ) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Option('Hugging Face dataset repo')]
        string $repo = 'museado/folios',

        #[Option('Folio code to pull (e.g. mus/cleveland)')]
        ?string $dataset = null,

        #[Option('Pull all folios in the repo')]
        bool $all = false,

        #[Option('Provider code (e.g. mus)')]
        ?string $provider = null,

        #[Option('Replace existing folio files')]
        bool $force = false,
    ): int {
        $folioCodes = $this->resolveCodes($io, $repo, $dataset, $provider, $all);
        if ($folioCodes === []) {
            $io->warning('No folios to pull. Pass --dataset, --provider, or --all.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Pulling %d folio(s) from %s', count($folioCodes), $repo));
        $tmpDir = sys_get_temp_dir() . '/folio_pull_' . uniqid();
        mkdir($tmpDir, 0775, true);

        $pulled = 0;
        foreach ($folioCodes as $code) {
            $io->section($code);

            $target = $this->folios->path($code);
            if (is_file($target) && !$force) {
                $io->text('Already exists, skipping (use --force to replace)');
                continue;
            }

            $remoteFile = $code . '.folio.gz';
            $localGz = $tmpDir . '/' . str_replace('/', '_', $code) . '.folio.gz';

            $process = new Process(['hf', 'download', $repo, $remoteFile, '--type', 'dataset', '--local-dir', $tmpDir]);
            $process->setTimeout(120);
            $process->run();

            $downloadedFile = $tmpDir . '/' . $remoteFile;
            if (!$process->isSuccessful() || !is_file($downloadedFile)) {
                $io->error(sprintf('Download failed: %s', $process->getErrorOutput() ?: 'file not found'));
                continue;
            }

            $result = $this->archiveService->restore($downloadedFile, $code, $force);
            $inflated = $this->archiveService->inflate($result['target']);

            $io->text(sprintf(
                'Restored: %s (%d indexes, %d views, %d FTS rows)',
                $result['target'],
                $inflated['indexes'],
                $inflated['views'],
                $inflated['ftsRows'],
            ));
            $pulled++;
        }

        // Cleanup
        $this->rmdir($tmpDir);

        $io->success(sprintf('Pulled %d folio(s)', $pulled));
        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function resolveCodes(SymfonyStyle $io, string $repo, ?string $dataset, ?string $provider, bool $all): array
    {
        if ($dataset !== null && $dataset !== '') {
            return [$dataset];
        }

        // List files in the HF repo to discover available folios
        $process = new Process(['hf', 'datasets', 'list', $repo, '--files', '--type', 'dataset']);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            // Fallback: try hf download with glob pattern — but simpler to just list
            $io->warning('Could not list repo files. Specify --dataset explicitly.');
            return [];
        }

        $codes = [];
        foreach (explode("\n", $process->getOutput()) as $line) {
            $line = trim($line);
            if (!str_ends_with($line, '.folio.gz')) {
                continue;
            }
            $code = substr($line, 0, -strlen('.folio.gz'));
            if ($provider !== null && !str_starts_with($code, $provider . '/')) {
                continue;
            }
            if ($all || $provider !== null) {
                $codes[] = $code;
            }
        }

        sort($codes);
        return $codes;
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
