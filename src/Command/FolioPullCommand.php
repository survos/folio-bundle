<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Service\{FolioArchiveService,FolioService};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

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
        $remotes = $this->resolveRemotes($io, $repo, $dataset, $provider, $all);
        if ($remotes === []) {
            $io->warning('No folios to pull. Pass --dataset, --provider, or --all.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Syncing %d folio(s) from %s', count($remotes), $repo));
        $tmpDir = sys_get_temp_dir() . '/folio_pull_' . uniqid();
        mkdir($tmpDir, 0775, true);

        $pulled = 0;
        $skipped = 0;
        foreach ($remotes as $remote) {
            $code = $remote['code'];
            $io->section($code);

            $target = $this->folios->path($code);
            if (is_file($target) && !$force && $this->isCurrent($target, $remote)) {
                $io->text('Current, skipping');
                $skipped++;
                continue;
            }

            $downloadedFile = $this->download($io, $repo, $remote['path'], $tmpDir);
            if ($downloadedFile === null) {
                continue;
            }

            $result = $this->archiveService->restore($downloadedFile, $code, true);
            $inflated = $this->archiveService->inflate($result['target']);
            $this->writeSyncMetadata($result['target'], $repo, $remote);

            $io->text(sprintf(
                'Restored: %s (%d indexes, %d views, %d FTS rows)',
                $result['target'],
                $inflated['indexes'],
                $inflated['views'],
                $inflated['ftsRows'],
            ));
            $pulled++;
        }

        $this->rmdir($tmpDir);

        $io->success(sprintf('Pulled %d folio(s), skipped %d current folio(s)', $pulled, $skipped));
        return Command::SUCCESS;
    }

    /**
     * @return list<array{code:string,path:string,size:int|null,oid:string|null,lfsOid:string|null,xetHash:string|null}>
     */
    private function resolveRemotes(SymfonyStyle $io, string $repo, ?string $dataset, ?string $provider, bool $all): array
    {
        if ($dataset !== null && $dataset !== '') {
            $path = $dataset . '.folio.gz';
            return [$this->remoteFromPath($path)];
        }

        if (!$all && ($provider === null || $provider === '')) {
            return [];
        }

        $entries = $this->listRepoEntries($io, $repo);
        $remotes = [];
        foreach ($entries as $entry) {
            if (($entry['type'] ?? null) !== 'file' || !is_string($entry['path'] ?? null)) {
                continue;
            }
            $path = $entry['path'];
            if (!str_ends_with($path, '.folio.gz')) {
                continue;
            }
            $code = substr($path, 0, -strlen('.folio.gz'));
            if ($provider !== null && $provider !== '' && !str_starts_with($code, $provider . '/')) {
                continue;
            }
            $remotes[] = $this->remoteFromEntry($entry);
        }

        usort($remotes, static fn (array $a, array $b): int => $a['code'] <=> $b['code']);
        return $remotes;
    }

    /** @return list<array<string,mixed>> */
    private function listRepoEntries(SymfonyStyle $io, string $repo): array
    {
        $url = sprintf('https://huggingface.co/api/datasets/%s/tree/main?recursive=true', $repo);
        $json = $this->fetch($url);
        if ($json === null) {
            $io->warning('Could not list repo files. Specify --dataset explicitly.');
            return [];
        }

        $entries = json_decode($json, true);
        if (!is_array($entries)) {
            $io->warning('Could not parse repo file list. Specify --dataset explicitly.');
            return [];
        }

        return array_values(array_filter($entries, is_array(...)));
    }

    /** @param array<string,mixed> $entry */
    private function remoteFromEntry(array $entry): array
    {
        $path = (string) $entry['path'];
        $lfs = is_array($entry['lfs'] ?? null) ? $entry['lfs'] : [];

        return [
            'code' => substr($path, 0, -strlen('.folio.gz')),
            'path' => $path,
            'size' => isset($entry['size']) ? (int) $entry['size'] : null,
            'oid' => is_string($entry['oid'] ?? null) ? $entry['oid'] : null,
            'lfsOid' => is_string($lfs['oid'] ?? null) ? $lfs['oid'] : null,
            'xetHash' => is_string($entry['xetHash'] ?? null) ? $entry['xetHash'] : null,
        ];
    }

    private function remoteFromPath(string $path): array
    {
        return [
            'code' => substr($path, 0, -strlen('.folio.gz')),
            'path' => $path,
            'size' => null,
            'oid' => null,
            'lfsOid' => null,
            'xetHash' => null,
        ];
    }

    /** @param array<string,mixed> $remote */
    private function isCurrent(string $target, array $remote): bool
    {
        $metadataFile = $this->metadataFile($target);
        if (!is_file($metadataFile)) {
            return false;
        }

        $metadata = json_decode((string) file_get_contents($metadataFile), true);
        if (!is_array($metadata)) {
            return false;
        }

        foreach (['path', 'size', 'oid', 'lfsOid', 'xetHash'] as $key) {
            if ($remote[$key] !== null && ($metadata[$key] ?? null) !== $remote[$key]) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $remote */
    private function writeSyncMetadata(string $target, string $repo, array $remote): void
    {
        file_put_contents($this->metadataFile($target), json_encode(
            $remote + [
                'repo' => $repo,
                'syncedAt' => gmdate(DATE_ATOM),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function metadataFile(string $target): string
    {
        return $target . '.hf.json';
    }

    private function download(SymfonyStyle $io, string $repo, string $remoteFile, string $tmpDir): ?string
    {
        $downloadedFile = $tmpDir . '/' . $remoteFile;
        $downloadedDir = dirname($downloadedFile);
        if (!is_dir($downloadedDir) && !mkdir($downloadedDir, 0775, true) && !is_dir($downloadedDir)) {
            throw new \RuntimeException(sprintf('Unable to create temporary download directory "%s".', $downloadedDir));
        }

        $url = sprintf(
            'https://huggingface.co/datasets/%s/resolve/main/%s',
            $repo,
            implode('/', array_map(rawurlencode(...), explode('/', $remoteFile))),
        );
        $io->text('Downloading: ' . $url);

        $source = $this->open($url);
        if (!is_resource($source)) {
            $io->error('Download failed.');
            return null;
        }

        $target = @fopen($downloadedFile, 'wb');
        if (!is_resource($target)) {
            fclose($source);
            throw new \RuntimeException(sprintf('Unable to write downloaded folio archive "%s".', $downloadedFile));
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        if (!is_file($downloadedFile) || filesize($downloadedFile) === 0) {
            $io->error('Download failed.');
            return null;
        }

        return $downloadedFile;
    }

    private function fetch(string $url): ?string
    {
        $source = $this->open($url);
        if (!is_resource($source)) {
            return null;
        }

        $contents = stream_get_contents($source);
        fclose($source);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /** @return resource|null */
    private function open(string $url)
    {
        $headers = [];
        $token = getenv('HF_TOKEN') ?: getenv('HUGGING_FACE_HUB_TOKEN') ?: null;
        if (is_string($token) && $token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $context = stream_context_create([
            'http' => [
                'follow_location' => 1,
                'header' => $headers,
                'timeout' => 120,
            ],
        ]);

        $source = @fopen($url, 'rb', false, $context);
        return is_resource($source) ? $source : null;
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
