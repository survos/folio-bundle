<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Service\{FolioArchiveService,FolioService};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand('folio:publish', 'Deflate and upload folio archives to Hugging Face')]
final class FolioPublishCommand
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioArchiveService $archiveService,
    ) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Option('Hugging Face dataset repo (e.g. museado/folios)')]
        string $repo = 'museado/folios',

        #[Option('Folio code to publish (e.g. mus/cleveland)')]
        ?string $dataset = null,

        #[Option('Publish all folios')]
        bool $all = false,

        #[Option('Provider code (e.g. mus)')]
        ?string $provider = null,

        #[Option('Skip upload, only deflate')]
        bool $deflateOnly = false,
    ): int {
        $folioDir = $this->folios->rootPath();
        $folioPaths = $this->resolveFolios($folioDir, $dataset, $provider, $all);

        if ($folioPaths === []) {
            $io->warning('No folios found to publish.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Publishing %d folio(s) to %s', count($folioPaths), $repo));
        $published = [];

        foreach ($folioPaths as $folioCode => $path) {
            $io->section($folioCode);

            $result = $this->archiveService->archive($folioCode);
            $ratio = $result['sourceBytes'] > 0
                ? round(100 * (1 - $result['archiveBytes'] / $result['sourceBytes']))
                : 0;
            $io->text(sprintf(
                'Deflated: %s → %s (%d%% smaller)',
                $this->formatBytes($result['sourceBytes']),
                $this->formatBytes($result['archiveBytes']),
                $ratio,
            ));

            if ($deflateOnly) {
                $published[] = $result['archive'];
                continue;
            }

            $pathInRepo = $folioCode . '.folio.gz';
            $process = new Process(['hf', 'upload', $repo, $result['archive'], $pathInRepo, '--type', 'dataset']);
            $process->setTimeout(300);
            $process->run();

            if (!$process->isSuccessful()) {
                $io->error(sprintf('Upload failed for %s: %s', $folioCode, $process->getErrorOutput()));
                continue;
            }

            $io->text('Uploaded: ' . $pathInRepo);
            $published[] = $pathInRepo;
        }

        $io->success(sprintf('Published %d folio(s)', count($published)));
        return Command::SUCCESS;
    }

    /** @return array<string, string> folioCode => path */
    private function resolveFolios(string $folioDir, ?string $dataset, ?string $provider, bool $all): array
    {
        if ($dataset !== null && $dataset !== '') {
            $path = $this->folios->path($dataset);
            return is_file($path) ? [$dataset => $path] : [];
        }

        $folios = [];
        $searchDir = $folioDir . '/' . ($provider ?? '');

        if (!is_dir($searchDir)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($searchDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.folio')) {
                continue;
            }
            if (str_contains($file->getFilename(), '_bootstrap')) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($folioDir) + 1);
            $folioCode = substr($relative, 0, -strlen('.folio'));
            if (!$all && $provider === null) {
                continue;
            }
            $folios[$folioCode] = $file->getPathname();
        }

        ksort($folios);
        return $folios;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
