<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\DatasetBundle\Entity\Artifact;
use Survos\DatasetBundle\Event\DatasetArtifactUpdatedEvent;
use Survos\FolioBundle\Service\{FolioArchivePreparer,FolioArchiveService,FolioIngestService,FolioRegistry,FolioService};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsCommand(
    name: 'folio:build',
    description: 'Build a working folio from normalized JSONL (and, with --gz, a compressed index-free archive for upload).',
    help: <<<'END'
        Producer: builds folio artifacts from normalized JSONL.

          1. ingest rows into a row-only SQLite (no indexes/FTS/views)
          2. inflate a working copy           -> folio/<provider>/<code>.folio              (--inflate, default)
          3. with --gz: snapshot a compressed index-free archive -> folio-archive/<provider>/<code>.folio.gz

        By default only the working folio is built. Compression is slow and only needed to ship to
        S3/HF, so the .gz archive is opt-in via --gz (taken from the row-only DB BEFORE indexing, so
        nothing is built only to be thrown away). A build/upload box can pass --gz --no-inflate.

        Registry updates are event-driven: folio:build dispatches artifact events that dataset-bundle
        listens to, so DatasetInfo/Artifact rows are updated without running dataset:scan after each build.

          folio:build --dataset=mus/walters       build the working folio, print a browse link
          folio:build --provider=dc --gz --no-inflate   compress every dc folio for upload, no working copy
          folio:build --all --dry-run             show what would be (re)built
        END,
)]
final class FolioBuildCommand
{
    public function __construct(
        private readonly FolioIngestService $ingest,
        private readonly FolioRegistry $registry,
        private readonly FolioService $folios,
        private readonly FolioArchiveService $archiveService,
        private readonly FolioArchivePreparer $preparer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly bool $kernelDebug = false,
        private readonly ?string $folioServer = null,
    ) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Option('Folio code to build (e.g. mus/walters)')]
        ?string $dataset = null,

        #[Option('Build all datasets that have a normalized source')]
        bool $all = false,

        #[Option('Build all datasets for a provider')]
        ?string $provider = null,

        #[Option('Also write the compressed index-free .gz archive (for S3/HF upload; slow, off by default)')]
        bool $gz = false,

        #[Option('Build the inflated working folio with indexes + FTS + views (default; --no-inflate to skip)')]
        ?bool $inflate = null,

        #[Option('Build even when the normalized source is empty/missing')]
        bool $allowEmpty = false,

        #[Option('Rebuild even if artifacts are newer than the source')]
        bool $force = false,

        #[Option('Report what would happen without writing anything')]
        bool $dryRun = false,

        #[Option('Core name (default: from DatasetInfo or "obj")')]
        ?string $core = null,

        #[Option('Identifier field')]
        string $idField = 'id',

        #[Option('Label field')]
        string $labelField = 'label',

        #[Option('Flush batch size')]
        int $batch = 500,
    ): int {
        $startedAt = microtime(true);
        $inflate ??= true;
        if (!$gz && !$inflate) {
            $io->error('Nothing to build: pass --gz and/or keep --inflate so something is produced.');
            return Command::INVALID;
        }

        try {
            $datasets = $this->registry->datasets(
                datasetKey: ($dataset ?? '') ?: null,
                provider: ($provider ?? '') ?: null,
                all: $all,
                requireSource: false,
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }

        if ($datasets === []) {
            $io->warning('No matching datasets. Run dataset:scan first.');
            return Command::SUCCESS;
        }

        $io->title(sprintf('Building %d folio(s)%s', count($datasets), $dryRun ? ' — dry run' : ''));

        if ($this->kernelDebug) {
            $io->warning('Debug mode is on — ingest is much slower. Re-run with --no-debug for a large speedup.');
        }

        $built = 0;
        $skipped = 0;
        $archiveBytesTotal = 0;
        $coreFilter = ($core ?? '') ?: null;

        foreach ($datasets as $info) {
            $code = $info->datasetKey;
            $io->section($code);

            $sources = $this->ingest->resolveSources($info, $coreFilter);
            if ($sources === [] && !$allowEmpty) {
                $io->writeln('  ⚠ skipped — no rows in normalized source (use --allow-empty to build anyway)');
                $skipped++;
                continue;
            }

            $workingPath = $this->folios->path($code);
            $archivePath = $this->folios->archivePath($code);

            if (!$force && $this->isFresh($sources, $gz ? $archivePath : null, $inflate ? $workingPath : null)) {
                $io->writeln('  ✓ up to date — skipping (use --force to rebuild)');
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $exists = is_file($archivePath) || is_file($workingPath);
                $io->writeln(sprintf(
                    '  %s → %s',
                    $exists ? 'would rebuild' : 'would build',
                    implode(' + ', array_filter([$inflate ? 'working' : null, $gz ? 'archive (.gz)' : null])),
                ));
                continue;
            }

            // Step 1: rows-only ingest — no FTS/index event, so the archive snapshot is clean.
            // Pass $io so the service renders a progress bar seeded from sidecar row counts.
            $result = $this->ingest->ingestDataset($info, $coreFilter, $idField, $labelField, $batch, dispatchFinished: false, io: $io);
            $io->writeln(sprintf('  rows     %s', number_format($result['rows'])));
            if ($io->isVerbose()) {
                foreach ($result['cores'] as $coreCode => $coreInfo) {
                    $io->writeln(sprintf('  · core %s: %s rows', $coreCode, number_format($coreInfo['count'])));
                }
                if ($result['terms'] > 0) {
                    $io->writeln(sprintf('  · terms: %s', number_format($result['terms'])));
                }
                if ($result['links'] > 0) {
                    $io->writeln(sprintf('  · links: %s', number_format($result['links'])));
                }
            }

            // Step 2 (--gz only): snapshot the compressed index-free archive (archive() also writes the
            // schema snapshot onto the working folio, which inflate() needs for its views).
            if ($gz) {
                $io->writeln('  compressing archive…');
                $t = microtime(true);
                $res = $this->archiveService->archive($code, $archivePath);
                $archiveBytesTotal += $res['archiveBytes'];
                $ratio = $res['sourceBytes'] > 0 ? (int) round(100 * (1 - $res['archiveBytes'] / $res['sourceBytes'])) : 0;
                $io->writeln(sprintf('  archive  %s  (%d%% smaller, %s)  %s', $this->humanBytes($res['archiveBytes']), $ratio, $this->fmtSecs(microtime(true) - $t), $archivePath));
                $this->dispatchArtifactUpdated(
                    datasetKey: $code,
                    type: Artifact::TYPE_FOLIO_ARCHIVE,
                    uri: $archivePath,
                    rowCount: (int) $result['rows'],
                    dtoCounts: null,
                    metadata: [
                        'compressed' => true,
                        'sourceBytes' => $res['sourceBytes'],
                        'archiveBytes' => $res['archiveBytes'],
                    ],
                );
            } else {
                // inflate-only: still need the schema snapshot for view generation.
                $this->preparer->prepare($workingPath, ['builtAt' => gmdate(DATE_ATOM)]);
            }

            // Step 3: working folio, or discard the row-only scratch.
            if ($inflate) {
                $io->writeln('  inflating (indexes + FTS + views)…');
                $t = microtime(true);
                $inf = $this->archiveService->inflate($workingPath);
                $io->writeln(sprintf(
                    '  working  %s  (%d indexes · FTS %s rows · %d views)  in %s  %s',
                    $this->humanBytes(filesize($workingPath) ?: 0),
                    $inf['indexes'],
                    number_format($inf['ftsRows']),
                    $inf['views'],
                    $this->fmtSecs(microtime(true) - $t),
                    $workingPath,
                ));
                if (isset($inf['timing'])) {
                    $io->writeln(sprintf(
                        '           indexes %s · views %s · fts %s',
                        $this->fmtSecs($inf['timing']['indexes']),
                        $this->fmtSecs($inf['timing']['views']),
                        $this->fmtSecs($inf['timing']['fts']),
                    ));
                }
                $io->writeln('  browse   ' . $this->browseLink($code));
                $this->dispatchArtifactUpdated(
                    datasetKey: $code,
                    type: Artifact::TYPE_FOLIO,
                    uri: $workingPath,
                    rowCount: (int) $result['rows'],
                    dtoCounts: $this->dtoCounts($result['cores']),
                    metadata: [
                        'cores' => $this->coreMetadata($result['cores']),
                        'coreCounts' => $this->coreCounts($result['cores']),
                        'terms' => (int) $result['terms'],
                        'links' => (int) $result['links'],
                    ],
                );
            } elseif (is_file($workingPath)) {
                unlink($workingPath);
            }

            $built++;
        }

        $io->newLine();
        $io->writeln(sprintf(
            '%d selected · %d built · %d skipped%s · %s total',
            count($datasets),
            $built,
            $skipped,
            $archiveBytesTotal > 0 ? ' · archives ' . $this->humanBytes($archiveBytesTotal) : '',
            Helper::formatTime(microtime(true) - $startedAt),
        ));
        return Command::SUCCESS;
    }

    /** @param array<string,array{count:int,classCounts?:array<string,int>}> $cores */
    private function dtoCounts(array $cores): ?array
    {
        $counts = [];
        foreach ($cores as $core) {
            foreach (($core['classCounts'] ?? []) as $class => $count) {
                $counts[$class] = ($counts[$class] ?? 0) + (int) $count;
            }
        }

        arsort($counts);
        return $counts ?: null;
    }

    /** @param array<string,array{count:int,classCounts?:array<string,int>}> $cores */
    private function coreCounts(array $cores): array
    {
        $counts = [];
        foreach ($cores as $code => $info) {
            $counts[(string) $code] = (int) $info['count'];
        }

        return $counts;
    }

    /** @param array<string,array{count:int,classCounts?:array<string,int>}> $cores */
    private function coreMetadata(array $cores): array
    {
        $metadata = [];
        foreach ($cores as $code => $info) {
            $metadata[] = [
                'code' => (string) $code,
                'rowCount' => (int) $info['count'],
            ];
        }

        return $metadata;
    }

    /**
     * @param array<string,int>|null $dtoCounts
     * @param array<string,mixed> $metadata
     */
    private function dispatchArtifactUpdated(string $datasetKey, string $type, string $uri, ?int $rowCount, ?array $dtoCounts, array $metadata): void
    {
        $this->dispatcher?->dispatch(new DatasetArtifactUpdatedEvent(
            datasetKey: $datasetKey,
            type: $type,
            uri: $uri,
            rowCount: $rowCount,
            dtoCounts: $dtoCounts,
            metadata: $metadata,
        ));
    }

    /** All requested artifacts exist and are newer than the newest source file. */
    private function isFresh(array $sources, ?string $archivePath, ?string $workingPath): bool
    {
        if ($sources === []) {
            return false;
        }

        $newestSource = 0;
        foreach ($sources as $file) {
            $mtime = filemtime($file);
            if ($mtime === false) {
                return false;
            }
            $newestSource = max($newestSource, $mtime);
        }

        foreach ([$archivePath, $workingPath] as $artifact) {
            if ($artifact === null) {
                continue;
            }
            if (!is_file($artifact) || (int) filemtime($artifact) < $newestSource) {
                return false;
            }
        }

        return true;
    }

    /**
     * Absolute, clickable browse URL. folio_server supplies the host when the UX lives on
     * another app; otherwise the router's framework.router.default_uri provides scheme+host.
     */
    private function browseLink(string $datasetKey): string
    {
        [$provider, $dataset] = array_pad(explode('/', $datasetKey, 2), 2, $datasetKey);
        $params = ['provider' => $provider, 'dataset' => $dataset];

        try {
            if ($this->folioServer !== null && $this->folioServer !== '') {
                return rtrim($this->folioServer, '/') . $this->urlGenerator->generate('survos_folio_show', $params);
            }

            return $this->urlGenerator->generate('survos_folio_show', $params, UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            // UX not installed here (registry-only app) and no folio_server configured.
            $base = $this->folioServer !== null && $this->folioServer !== '' ? rtrim($this->folioServer, '/') : '';

            return $base . '/folio/' . $provider . '/' . $dataset;
        }
    }

    private function fmtSecs(float $seconds): string
    {
        return $seconds >= 1 ? sprintf('%.1fs', $seconds) : sprintf('%dms', (int) round($seconds * 1000));
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 1) . ' MB';
        }

        return round($bytes / 1024, 1) . ' KB';
    }
}
