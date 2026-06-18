<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use League\Flysystem\FilesystemOperator;
use Survos\FolioBundle\Service\{FolioArchiveService,FolioService};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Zenstruck\Bytes;

#[AsCommand('folio:pull', 'Download folio archives from the folio_archive storage (or Hugging Face) and inflate')]
final class FolioPullCommand
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioArchiveService $archiveService,
        // Optional: apps without a `folio_archive.storage` flysystem storage (e.g. HF-only) inject null.
        #[Target('folio_archive.storage')]
        private readonly ?FilesystemOperator $archiveStorage = null,
        private readonly ?HttpClientInterface $http = null,
        // survos_folio.folio_server — the live folio site; the default API source for pulls.
        private readonly ?string $folioServer = null,
    ) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Option('Folio code to pull (e.g. mus/cleveland)')]
        ?string $dataset = null,

        #[Option('Pull all folios in the source')]
        bool $all = false,

        #[Option('Provider code (e.g. mus)')]
        ?string $provider = null,

        #[Option('Replace existing folio files')]
        bool $force = false,

        #[Option('Pull over HTTP from a folio API base URL (e.g. https://zm.example) instead of storage')]
        ?string $api = null,

        #[Option('Pull from Hugging Face instead of the folio_archive storage')]
        bool $hf = false,

        #[Option('Hugging Face dataset repo (with --hf)')]
        string $repo = 'museado/folios',
    ): int {
        if (!$hf) {
            // Default to the folio API (folio_server) unless --api overrides it; storage is the fallback.
            $apiBase = ($api !== null && $api !== '') ? $api : $this->folioServer;
            if ($apiBase !== null && $apiBase !== '') {
                return $this->pullFromApi($io, $apiBase, $dataset, $provider, $all, $force);
            }
            return $this->pullFromStorage($io, $dataset, $provider, $all, $force);
        }

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
     * Pull over plain HTTP from a folio API (e.g. zm's read-only `/folio/list.json` + download routes).
     * No SSH/credentials — just GET the JSON registry, download each `.folio.gz`, restore() + inflate().
     */
    private function pullFromApi(SymfonyStyle $io, string $baseUrl, ?string $dataset, ?string $provider, bool $all, bool $force): int
    {
        if ($this->http === null) {
            $io->error('No HTTP client available (require symfony/http-client).');
            return Command::FAILURE;
        }

        $baseUrl = rtrim($baseUrl, '/');

        // Nothing requested → just show what's available (first page of the registry).
        if (($dataset === null || $dataset === '') && ($provider === null || $provider === '') && !$all) {
            return $this->showApiList($io, $baseUrl);
        }

        $entries = $this->resolveApiEntries($io, $baseUrl, $dataset, $provider, $all);
        if ($entries === []) {
            $io->warning('No folios to pull. Pass --dataset, --provider, or --all.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Pulling %d folio(s) from %s', count($entries), $baseUrl));
        $tmpDir = sys_get_temp_dir() . '/folio_pull_' . uniqid();
        mkdir($tmpDir, 0775, true);

        $pulled = 0;
        $skipped = 0;
        foreach ($entries as $entry) {
            $code = (string) ($entry['datasetKey'] ?? '');
            $downloadUrl = (string) ($entry['downloadUrl'] ?? '');
            if ($code === '' || $downloadUrl === '') {
                continue;
            }
            $io->section($code);

            $target = $this->folios->path($code);
            if (is_file($target) && !$force) {
                $io->text('Exists, skipping (use --force to replace)');
                $skipped++;
                continue;
            }

            $localGz = $tmpDir . '/' . str_replace('/', '_', $code) . '.folio.gz';
            $out = fopen($localGz, 'wb');
            $response = $this->http->request('GET', $downloadUrl);
            foreach ($this->http->stream($response) as $chunk) {
                fwrite($out, $chunk->getContent());
            }
            fclose($out);
            $io->text(sprintf('Downloaded: %s (%s)', $downloadUrl, Bytes::parse(filesize($localGz) ?: 0)->humanize()));

            // restore() gunzips → working folio AND inflates (indexes + FTS + views).
            $result = $this->archiveService->restore($localGz, $code, $force);
            $io->text(sprintf(
                'Inflated: %s (%s, %s FTS rows)',
                $result['target'],
                Bytes::parse($result['targetBytes'])->humanize(),
                number_format($result['indexedRows']),
            ));
            $pulled++;
        }

        $this->rmdir($tmpDir);
        $io->success(sprintf('Pulled %d folio(s) from API, skipped %d existing', $pulled, $skipped));
        return Command::SUCCESS;
    }

    /**
     * @return list<array<string,mixed>> folio entries from `<baseUrl>/folio/list.json`
     *   (each has at least datasetKey + downloadUrl), filtered to the requested dataset.
     */
    private function resolveApiEntries(SymfonyStyle $io, string $baseUrl, ?string $dataset, ?string $provider, bool $all): array
    {
        if (!$all && ($dataset === null || $dataset === '') && ($provider === null || $provider === '')) {
            return [];
        }

        $query = [];
        if ($provider !== null && $provider !== '') {
            $query['provider'] = $provider;
        }
        $url = $baseUrl . '/folio/list.json' . ($query !== [] ? '?' . http_build_query($query) : '');

        $data = $this->http->request('GET', $url)->toArray(false);
        $folios = is_array($data['folios'] ?? null) ? $data['folios'] : [];

        if ($dataset !== null && $dataset !== '') {
            $folios = array_values(array_filter(
                $folios,
                static fn (array $f): bool => ($f['datasetKey'] ?? null) === $dataset,
            ));
            if ($folios === []) {
                $io->warning(sprintf('Not in API registry: %s', $dataset));
            }
        }

        return $folios;
    }

    /** GET <baseUrl>/folio/list.json and render the first page as a table — the "nothing requested" view. */
    private function showApiList(SymfonyStyle $io, string $baseUrl): int
    {
        if ($this->http === null) {
            $io->error('No HTTP client available (require symfony/http-client).');
            return Command::FAILURE;
        }

        $data = $this->http->request('GET', $baseUrl . '/folio/list.json')->toArray(false);
        $folios = is_array($data['folios'] ?? null) ? $data['folios'] : [];
        if ($folios === []) {
            $io->warning(sprintf('No folios available at %s', $baseUrl));
            return Command::SUCCESS;
        }

        $total = (int) ($data['count'] ?? count($folios));
        $io->title(sprintf('%d folio(s) at %s', $total, $baseUrl));

        $page = array_slice($folios, 0, 25);
        $io->table(['dataset', 'title', 'size', 'rows'], array_map(
            static fn (array $f): array => [
                (string) ($f['datasetKey'] ?? ''),
                (string) ($f['title'] ?? ''),
                (string) Bytes::parse($f['sizeBytes'] ?? 0)->humanize(),
                number_format((int) ($f['rowCount'] ?? 0)),
            ],
            $page,
        ));

        $io->writeln($total > count($page)
            ? sprintf('Showing first %d of %d. Pull with --dataset=<key>, --provider=<p>, or --all.', count($page), $total)
            : 'Pull with --dataset=<key>, --provider=<p>, or --all.');

        return Command::SUCCESS;
    }

    /**
     * Default source: read `<provider>/<code>.folio.gz` from the folio_archive flysystem storage
     * (SFTP/S3/local — backend is pure config) and inflate via the same restore() path as HF.
     */
    private function pullFromStorage(SymfonyStyle $io, ?string $dataset, ?string $provider, bool $all, bool $force): int
    {
        if ($this->archiveStorage === null) {
            $io->error('No folio_archive.storage configured. Add a flysystem storage named "folio_archive.storage", or pass --hf.');
            return Command::FAILURE;
        }

        $codes = $this->resolveStorageCodes($dataset, $provider, $all);
        if ($codes === []) {
            $io->warning('No folios to pull. Pass --dataset, --provider, or --all.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Pulling %d folio(s) from folio_archive storage', count($codes)));
        $tmpDir = sys_get_temp_dir() . '/folio_pull_' . uniqid();
        mkdir($tmpDir, 0775, true);

        $pulled = 0;
        $skipped = 0;
        foreach ($codes as $code) {
            $io->section($code);

            $target = $this->folios->path($code);
            if (is_file($target) && !$force) {
                $io->text('Exists, skipping (use --force to replace)');
                $skipped++;
                continue;
            }

            $remotePath = $code . '.folio.gz';
            if (!$this->archiveStorage->fileExists($remotePath)) {
                $io->warning(sprintf('Not in archive: %s', $remotePath));
                continue;
            }

            $localGz = $tmpDir . '/' . str_replace('/', '_', $code) . '.folio.gz';
            $stream = $this->archiveStorage->readStream($remotePath);
            $out = fopen($localGz, 'wb');
            stream_copy_to_stream($stream, $out);
            fclose($out);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $io->text(sprintf('Downloaded: %s (%s)', $remotePath, Bytes::parse(filesize($localGz) ?: 0)->humanize()));

            // restore() gunzips → working folio AND inflates (indexes + FTS + views).
            $result = $this->archiveService->restore($localGz, $code, $force);
            $io->text(sprintf(
                'Inflated: %s (%s, %s FTS rows)',
                $result['target'],
                Bytes::parse($result['targetBytes'])->humanize(),
                number_format($result['indexedRows']),
            ));
            $pulled++;
        }

        $this->rmdir($tmpDir);
        $io->success(sprintf('Pulled %d folio(s) from storage, skipped %d existing', $pulled, $skipped));
        return Command::SUCCESS;
    }

    /** @return list<string> folio codes (provider/code) available in the archive storage */
    private function resolveStorageCodes(?string $dataset, ?string $provider, bool $all): array
    {
        if ($dataset !== null && $dataset !== '') {
            return [$dataset];
        }
        if (!$all && ($provider === null || $provider === '')) {
            return [];
        }

        $codes = [];
        foreach ($this->archiveStorage->listContents('', true) as $item) {
            if (!$item->isFile() || !str_ends_with($item->path(), '.folio.gz')) {
                continue;
            }
            $code = substr($item->path(), 0, -strlen('.folio.gz'));
            if ($provider !== null && $provider !== '' && !str_starts_with($code, $provider . '/')) {
                continue;
            }
            $codes[] = $code;
        }

        sort($codes);
        return $codes;
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
