<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\Folio\PropertyStore;
use Survos\FolioBundle\Dto\FolioBuildInput;
use Survos\JsonlBundle\Service\JsonlStateService;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Builds one folio from caller-selected files. Publication bookkeeping belongs to the caller. */
final readonly class FolioBuilder
{
    public function __construct(
        private FolioIngestService $ingest,
        private FolioService $folios,
        private FolioArchiveService $archives,
        private FolioArchivePreparer $preparer,
        private JsonlStateService $jsonlState,
    ) {
    }

    /** @return array{path:string,barePath:string,archive:?array,rows:int,dtoCounts:?array,metadata:array,locale:?string} */
    public function build(FolioBuildInput $input, bool $archive = false, ?SymfonyStyle $io = null): array
    {
        $this->folios->closeActive();
        $this->jsonlState->closeAll();
        gc_collect_cycles();
        $code = $input->code;
        $locale = $input->locale !== $input->sourceLocale ? $input->locale : null;
        $this->folios->buildAt($code, $locale);
        $workingPath = $this->folios->path($code, locale: $locale);
        try {
            $result = $this->ingest->ingest($input, dispatchFinished: false, io: $io);
            if (0 === $result['rows']) {
                throw new \RuntimeException('Refusing to publish an empty folio: '.$code);
            }
            $pdo = new \PDO('sqlite:file:'.$workingPath.'?mode=ro');
            $properties = (new PropertyStore($pdo))->values();
            unset($pdo);
            $barePath = $this->archives->snapshotBare($code, $workingPath, locale: $locale);
            $compressed = null;
            if ($archive) {
                $compressed = $this->archives->archive($code, $this->folios->archivePath($code, locale: $locale), locale: $locale);
            } else {
                $this->preparer->prepare($workingPath, ['builtAt' => gmdate(DATE_ATOM)]);
            }
            $this->archives->inflate($workingPath);
            $this->finishWorkingFolio($workingPath, $io);
            $workingPath = $this->folios->finishBuildAt($code, $locale);
        } catch (\Throwable $e) {
            $this->folios->discardBuildAt($code, $locale);
            throw $e;
        } finally {
            $this->jsonlState->closeAll();
        }

        return [
            'path' => $workingPath,
            'barePath' => $barePath,
            'archive' => $compressed,
            'rows' => $result['rows'],
            'dtoCounts' => $this->dtoCounts($result['cores']),
            'locale' => $locale,
            'metadata' => [
                'folioProperties' => $properties,
                'cores' => $this->coreMetadata($result['cores']),
                'coreCounts' => $this->coreCounts($result['cores']),
                'terms' => $result['terms'],
                'links' => $result['links'],
                'pageCount' => $this->tableCount($workingPath, 'page'),
                'claimCount' => $this->tableCount($workingPath, 'claim'),
            ],
        ];
    }

    private function finishWorkingFolio(string $workingPath, ?SymfonyStyle $io): void
    {
        $this->folios->finalize();
        try {
            $pdo = new \PDO('sqlite:'.$workingPath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA busy_timeout = 30000');
            $mode = (string) $pdo->query('PRAGMA journal_mode = DELETE')->fetchColumn();
            $pdo = null;
        } catch (\Throwable $e) {
            $mode = $e->getMessage();
        }
        if ('delete' !== strtolower($mode)) {
            $io?->warning(sprintf('%s is still in WAL mode (%s): another connection holds it open. Readers on a read-only mount cannot open it until it is finalized.', $workingPath, $mode));
        }
    }

    private function tableCount(string $dbFile, string $table): int
    {
        try {
            $pdo = new \PDO('sqlite:'.$dbFile);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $exists = $pdo->query(sprintf(
                "SELECT 1 FROM sqlite_master WHERE type='table' AND name=%s",
                $pdo->quote($table),
            ))->fetchColumn();

            return false === $exists ? 0 : (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
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
            $core = [
                'code' => (string) $code,
                'rowCount' => (int) $info['count'],
            ];
            if (($info['classCounts'] ?? []) !== []) {
                $classCounts = $info['classCounts'];
                arsort($classCounts);
                $core['classCounts'] = $classCounts;
            }
            $metadata[] = $core;
        }

        return $metadata;
    }
}
