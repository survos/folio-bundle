<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\FolioBundle\Entity\{Core,Folio,LinkType,Page,Row,Term,TermSet};
use Survos\FolioBundle\Dto\PageDto;
use Survos\FolioBundle\Event\FolioIngestFinishedEvent;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\Service\JsonlCountService;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Restore normalized JSONL into a row-only folio (no secondary indexes, FTS, or views).
 *
 * Extracted from FolioIngestCommand so both folio:ingest and folio:build can reuse it.
 * When $dispatchFinished is true, a FolioIngestFinishedEvent is fired per core (the
 * FtsIndexListener rebuilds FTS) — this preserves folio:ingest's behavior. folio:build
 * passes false so it can snapshot the index-free archive *before* anything is indexed.
 */
final class FolioIngestService
{
    /**
     * Commit and reopen the ingest transaction after this many rows. SQLite cannot checkpoint the
     * WAL while a write transaction is open, so a single transaction spanning the whole ingest lets
     * the -wal file (and page cache) grow without bound on large collections. reset() rebuilds the
     * folio from bootstrap on every run, so a partial commit is safe — a failed build is simply reset
     * and re-ingested.
     */
    private const CHECKPOINT_ROWS = 50_000;

    /**
     * Physical `item`/`link` columns in bind order for the raw bulk-insert path. These mirror the
     * Row/Link entity mappings (snake_case via the underscore naming strategy). The ORM is bypassed
     * here on purpose — see {@see FolioBulkInserter} — so if the entity mapping changes, update these.
     */
    private const ITEM_COLUMNS = ['id', 'core_id', 'local_id', 'label', 'dto_type', 'dto_data', 'extras', 'raw'];
    private const LINK_COLUMNS = ['id', 'link_type_id', 'left_core', 'left_id', 'right_core', 'right_id', 'extras'];
    private const PAGE_COLUMNS = ['id', 'row_id', 'seq', 'page_index', 'type', 'url', 'media_id', 'text', 'dense_summary', 'ledger', 'layout', 'width', 'height'];

    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioRegistry $registry,
        private readonly FolioDtoTypeResolver $dtoTypeResolver,
        private readonly FolioSummaryService $summaryService,
        private readonly DataPaths $dataPaths,
        private readonly JsonlCountService $counter,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {}

    /**
     * Resolve the row-bearing source files for a dataset's cores.
     *
     * @return array<string,string> core => source JSONL path
     */
    public function resolveSources(DatasetInfo $dataset, ?string $coreFilter = null): array
    {
        $sources = [];
        foreach ($this->registry->cores($dataset, $coreFilter) as $core) {
            $file = $this->registry->sourceFile($dataset, $core);
            if ($file !== null) {
                $sources[$core] = $file;
            }
        }

        return $sources;
    }

    /**
     * @return array{rows:int,skipped:int,terms:int,links:int,cores:array<string,array{count:int,file:string}>}
     */
    public function ingestDataset(
        DatasetInfo $dataset,
        ?string $coreFilter = null,
        string $idField = 'id',
        string $labelField = 'label',
        int $batch = 500,
        bool $dispatchFinished = true,
        ?SymfonyStyle $io = null,
    ): array {
        $batch = max(1, $batch);
        $sources = $this->resolveSources($dataset, $coreFilter);
        if ($sources === []) {
            return ['rows' => 0, 'terms' => 0, 'links' => 0, 'cores' => []];
        }

        // Restore once per dataset, then ingest every selected core into the same folio.
        $this->folios->reset($dataset->datasetKey);
        $ctx = $this->folios->context($dataset->datasetKey, ensureSchema: true);

        // Bulk load into a freshly-reset, rebuildable folio we are the sole writer of: trade durability
        // for speed. One transaction (no per-batch commits, no mid-ingest WAL checkpoints) +
        // synchronous=OFF (no fsync). If this throws, the CLI exits, the next run resets the folio, and
        // the pragma resets on reconnect; restored explicitly on success.
        $conn = $ctx->em->getConnection();
        $conn->executeStatement('PRAGMA synchronous = OFF');
        $conn->beginTransaction();

        $folio = $ctx->em->find(Folio::class, $ctx->folioCode);
        $folio->label = $dataset->label;
        $folio->datasetKey = $dataset->datasetKey;
        $ctx->em->flush();

        // #2: drop non-unique secondary indexes for the duration of the load. Maintaining them per-row
        // across millions of inserts is a large slice of ingest cost; we rebuild each in a single
        // sorted pass once the data is in (far cheaper). Self-contained: the same DDL is replayed
        // before we return, so every caller (folio:build, folio:ingest) ends with the full index set.
        $deferredIndexes = $this->deferSecondaryIndexes($conn);

        // Seed the bar from sidecar row counts (no full scan) when an $io is supplied. It spans every
        // row-bearing phase — cores, terms, links — so one bar covers the whole ingest. (claims and
        // translations will join this list; the per-phase ingest calls below all advance the same bar.)
        if ($io !== null) {
            $this->startIngestProgress($io, $this->expectedRows($dataset->datasetKey, $sources));
        }

        $coreResults = [];
        $totalCount = 0;
        $sinceCommit = 0;
        // Hot path: write rows straight to the `item` table via FolioBulkInserter — no ORM identity
        // map, no per-row hydration, flat memory. One inserter is reused across every core (same
        // table, varying core_id). Low-volume metadata (folio, core) stays on the ORM by design.
        $items = new FolioBulkInserter($conn, 'item', self::ITEM_COLUMNS);
        foreach ($sources as $core => $file) {
            $coreEntity = new Core($folio, $core);
            $ctx->em->persist($coreEntity);
            $ctx->em->flush();
            $coreId = $coreEntity->id;

            $count = 0;
            foreach (JsonlReader::open($file) as $data) {
                $localId = (string) ($data[$idField] ?? $data['@id'] ?? $data['_id'] ?? '');
                if ($localId === '') {
                    throw new \RuntimeException(sprintf('Missing id near row %d in %s.', $count + 1, $file));
                }

                $label = isset($data[$labelField])
                    ? (string) $data[$labelField]
                    : (isset($data['title']) ? (string) $data['title'] : $localId);
                $dtoType = $this->dtoTypeResolver->typeFromPayload($data);
                try {
                    [$dtoData, $extras] = $this->splitDtoData($dtoType, $data);
                } catch (\TypeError) {
                    $dtoData = $data;
                    $extras = null;
                }

                // Order must match self::ITEM_COLUMNS. raw is not populated during ingest.
                $items->add([
                    Row::id($coreId, $localId),
                    $coreId,
                    $localId,
                    $label,
                    $dtoType,
                    $this->encodeJson($dtoData),
                    $this->encodeJson($extras),
                    null,
                ]);

                if (++$count % $batch === 0) {
                    $sinceCommit = $this->maybeCheckpoint($conn, $sinceCommit + $batch, $items);
                    $this->advanceIngestProgress($io, $batch);
                }
            }

            $items->flush();
            $totalCount += $count;
            $coreEntity->rowCount = $count;
            $coreEntity->fieldSummary = $this->registry->populatedFields($dataset, $core);
            $ctx->em->flush();
            $this->advanceIngestProgress($io, $count % $batch);

            if ($dispatchFinished) {
                $summary = $this->summaryService->summarize($ctx->path);
                $this->dispatcher?->dispatch(new FolioIngestFinishedEvent(
                    datasetKey: $dataset->datasetKey,
                    core: $core,
                    dbFile: $ctx->path,
                    sourceFile: $file,
                    summary: $summary,
                ));
            }

            $coreResults[$core] = ['count' => $count, 'file' => $file];
        }

        $termCount = $this->ingestTerms($ctx->em, $folio, $dataset->datasetKey, $batch, $io);
        $linkResult = $this->ingestLinks($ctx->em, $folio, $dataset->datasetKey, $batch, $sinceCommit, $io);
        $pageResult = $this->ingestPages($ctx->em, $dataset->datasetKey, $batch, $sinceCommit, $io);

        $this->finishIngestProgress($io);

        // #2: rebuild the deferred indexes now that every row is loaded (one bulk pass each).
        $this->rebuildIndexes($conn, $deferredIndexes, $io);

        $folio = $ctx->em->find(Folio::class, $ctx->folioCode);
        $folio->rowCount = $totalCount;
        $ctx->em->flush();

        $conn->commit();
        $conn->executeStatement('PRAGMA synchronous = NORMAL');

        return [
            'rows' => $totalCount,
            'skipped' => $items->skipped() + $linkResult['skipped'],
            'terms' => $termCount,
            'links' => $linkResult['count'],
            'pages' => $pageResult['count'],
            'cores' => $coreResults,
        ];
    }

    /**
     * Ingest page.jsonl → the `page` table. Each entry references its parent row by
     * {coreCode, localId}; per-page AI fields (text/denseSummary/ledger/layout) are
     * folded in by the enriched build and carried straight through here.
     *
     * @return array{count:int}
     */
    private function ingestPages(EntityManagerInterface $em, string $datasetKey, int $batch, int $sinceCommit, ?SymfonyStyle $io = null): array
    {
        $pageFile = $this->dataPaths->stageDir($datasetKey, 'normalize') . '/' . PageDto::FILENAME;
        if (!is_file($pageFile)) {
            return ['count' => 0];
        }

        $conn = $em->getConnection();
        $pages = new FolioBulkInserter($conn, 'page', self::PAGE_COLUMNS);
        $count = 0;

        foreach (JsonlReader::open($pageFile) as $data) {
            $coreCode = $this->requiredString($data, 'coreCode', $pageFile);
            $localId  = $this->requiredString($data, 'localId', $pageFile);
            $url      = $this->requiredString($data, 'url', $pageFile);
            $seq      = (int) ($data['seq'] ?? ($count + 1));
            $rowId    = Row::id($datasetKey . ':' . $coreCode, $localId);

            // Order must match self::PAGE_COLUMNS.
            $pages->add([
                Page::id($rowId, $seq),
                $rowId,
                $seq,
                (int) ($data['pageIndex'] ?? 0),
                is_scalar($data['type'] ?? null) ? (string) $data['type'] : null,
                $url,
                is_scalar($data['mediaId'] ?? null) ? (string) $data['mediaId'] : null,
                is_scalar($data['text'] ?? null) ? (string) $data['text'] : null,
                is_scalar($data['denseSummary'] ?? null) ? (string) $data['denseSummary'] : null,
                $this->encodeJson(is_array($data['ledger'] ?? null) ? $data['ledger'] : null),
                $this->encodeJson(is_array($data['layout'] ?? null) ? $data['layout'] : null),
                isset($data['width']) ? (int) $data['width'] : null,
                isset($data['height']) ? (int) $data['height'] : null,
            ]);

            if (++$count % $batch === 0) {
                $sinceCommit = $this->maybeCheckpoint($conn, $sinceCommit + $batch, $pages);
                $this->advanceIngestProgress($io, $batch);
            }
        }
        $pages->flush();

        return ['count' => $count];
    }

    /**
     * Bound WAL growth during a long ingest: once $sinceCommit crosses the threshold, commit and
     * reopen the transaction so SQLite can checkpoint the -wal file (it cannot while a write
     * transaction is open). Any pending bulk-insert buffer is flushed first so it lands in the
     * committed transaction. Returns the running counter (reset to 0 when a commit happens).
     */
    private function maybeCheckpoint(Connection $conn, int $sinceCommit, ?FolioBulkInserter $inserter = null): int
    {
        if ($sinceCommit < self::CHECKPOINT_ROWS) {
            return $sinceCommit;
        }

        $inserter?->flush();
        $conn->commit();
        $conn->beginTransaction();

        return 0;
    }

    /** Encode a JSON column value the way the read path expects: null stays null, arrays become text. */
    private function encodeJson(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Drop every non-unique secondary index, returning its CREATE DDL for later replay. PK and UNIQUE
     * indexes are kept — they enforce constraints, and the bulk upsert's ON CONFLICT(id) depends on
     * the primary key. Auto-indexes (sql IS NULL) are skipped; only user/ORM-declared indexes match.
     *
     * @return list<string>
     */
    private function deferSecondaryIndexes(Connection $conn): array
    {
        $indexes = $conn->fetchAllAssociative(
            "SELECT name, sql FROM sqlite_master WHERE type = 'index' AND sql IS NOT NULL AND UPPER(sql) NOT LIKE '%UNIQUE%'"
        );

        $ddl = [];
        foreach ($indexes as $index) {
            $ddl[] = (string) $index['sql'];
            $conn->executeStatement(sprintf('DROP INDEX %s', $index['name']));
        }

        return $ddl;
    }

    /** @param list<string> $ddl CREATE INDEX statements captured by {@see deferSecondaryIndexes()} */
    private function rebuildIndexes(Connection $conn, array $ddl, ?SymfonyStyle $io): void
    {
        if ($ddl === []) {
            return;
        }

        $io?->writeln(sprintf('  rebuilding %d deferred index(es)…', count($ddl)));
        foreach ($ddl as $sql) {
            $conn->executeStatement($sql);
        }
    }

    /**
     * @param array<string,string> $sources
     */
    private function expectedRows(string $datasetKey, array $sources): int
    {
        $normalizeDir = $this->dataPaths->stageDir($datasetKey, 'normalize');
        $expected = 0;
        foreach ($sources as $sourceFile) {
            $expected += $this->counter->rows($sourceFile);
        }
        foreach (['term.jsonl', 'link.jsonl'] as $phaseFile) {
            $path = $normalizeDir . '/' . $phaseFile;
            if (is_file($path)) {
                $expected += $this->counter->rows($path);
            }
        }

        return $expected;
    }

    private function startIngestProgress(SymfonyStyle $io, int $expected): void
    {
        $format = $expected > 0
            ? ' %current%/%max% [%bar%] %percent:3s%% elapsed:%elapsed:6s% estimated:%estimated:-6s% memory:%memory:6s%'
            : ' %current% [%bar%] elapsed:%elapsed:6s% memory:%memory:6s%';

        $io->progressStart($expected, $format);
    }

    private function advanceIngestProgress(?SymfonyStyle $io, int $steps): void
    {
        if ($io === null || $steps <= 0) {
            return;
        }

        $io->progressAdvance($steps);
    }

    private function finishIngestProgress(?SymfonyStyle $io): void
    {
        if ($io === null) {
            return;
        }

        $io->progressFinish();
    }

    private function ingestTerms(EntityManagerInterface $em, Folio $folio, string $datasetKey, int $batch, ?SymfonyStyle $io = null): int
    {
        $normalizeDir = $this->dataPaths->stageDir($datasetKey, 'normalize');
        $termSetFile = $normalizeDir . '/termSet.jsonl';
        $termFile = $normalizeDir . '/term.jsonl';

        if (!is_file($termSetFile) || !is_file($termFile)) {
            return 0;
        }

        $sets = [];
        foreach (JsonlReader::open($termSetFile) as $data) {
            $code = $this->requiredString($data, 'code', $termSetFile);
            $set = new TermSet($folio, $code);
            $set->label = is_scalar($data['label'] ?? null) ? (string) $data['label'] : null;
            $set->description = is_scalar($data['description'] ?? null) ? (string) $data['description'] : null;
            $set->rules = is_array($data['rules'] ?? null) ? $data['rules'] : null;
            $set->meta = is_array($data['meta'] ?? null) ? $data['meta'] : null;
            $set->enabled = !array_key_exists('enabled', $data) || (bool) $data['enabled'];
            $em->persist($set);
            $sets[$code] = $set;
        }
        $em->flush();

        $pendingParents = [];
        $count = 0;
        $sinceCommit = 0;
        $conn = $em->getConnection();
        foreach (JsonlReader::open($termFile) as $data) {
            $setCode = $this->requiredString($data, 'termSet', $termFile);
            $set = $sets[$setCode] ?? null;
            if (!$set instanceof TermSet) {
                throw new \RuntimeException(sprintf('Unknown term set "%s" in %s.', $setCode, $termFile));
            }

            $code = $this->requiredString($data, 'code', $termFile);
            $term = new Term($set, $code);
            $term->path = is_scalar($data['path'] ?? null) && trim((string) $data['path']) !== '' ? trim((string) $data['path']) : $code;
            $term->label = is_scalar($data['label'] ?? null) ? (string) $data['label'] : $code;
            $term->description = is_scalar($data['description'] ?? null) ? (string) $data['description'] : null;
            $term->rules = is_array($data['rules'] ?? null) ? $data['rules'] : null;
            $term->meta = is_array($data['meta'] ?? null) ? $data['meta'] : null;
            $term->extras = array_diff_key($data, array_flip([
                'termSet',
                'code',
                'path',
                'label',
                'description',
                'rules',
                'meta',
                'enabled',
                'sort',
                'parent',
                'parentCode',
            ])) ?: null;
            $term->enabled = !array_key_exists('enabled', $data) || (bool) $data['enabled'];
            $term->sort = is_numeric($data['sort'] ?? null) ? (int) $data['sort'] : null;
            $parentCode = is_scalar($data['parent'] ?? $data['parentCode'] ?? null) ? trim((string) ($data['parent'] ?? $data['parentCode'])) : '';
            if ($parentCode !== '') {
                $pendingParents[$term->id] = $set->id . ':' . $parentCode;
            }

            $em->persist($term);

            if (++$count % $batch === 0) {
                $em->flush();
                // Don't retain every Term in the UnitOfWork; parents are resolved by find() below.
                $em->clear();
                // Re-attach the small term-set list so new Terms keep a managed parent after clear.
                foreach ($sets as $setCode => $set) {
                    $sets[$setCode] = $em->find(TermSet::class, $set->id);
                }
                $sinceCommit = $this->maybeCheckpoint($conn, $sinceCommit + $batch);
                $this->advanceIngestProgress($io, $batch);
            }
        }
        $em->flush();
        $this->advanceIngestProgress($io, $count % $batch);

        foreach ($pendingParents as $termId => $parentId) {
            $term = $em->find(Term::class, $termId);
            $parent = $em->find(Term::class, $parentId);
            if ($term instanceof Term && $parent instanceof Term) {
                $term->parent = $parent;
            }
        }
        $em->flush();

        return $count;
    }

    /**
     * @return array{count:int,skipped:int}
     */
    private function ingestLinks(EntityManagerInterface $em, Folio $folio, string $datasetKey, int $batch, int $sinceCommit, ?SymfonyStyle $io = null): array
    {
        $normalizeDir = $this->dataPaths->stageDir($datasetKey, 'normalize');
        $linkTypeFile = $normalizeDir . '/linkType.jsonl';
        $linkFile = $normalizeDir . '/link.jsonl';

        if (!is_file($linkTypeFile) || !is_file($linkFile)) {
            return ['count' => 0, 'skipped' => 0];
        }

        $types = [];
        foreach (JsonlReader::open($linkTypeFile) as $data) {
            $code = $this->requiredString($data, 'code', $linkTypeFile);
            $subjectCore = $this->requiredString($data, 'subjectCore', $linkTypeFile);
            $objectCore = $this->requiredString($data, 'objectCore', $linkTypeFile);

            $type = new LinkType($folio, $subjectCore, $code, $objectCore);
            $type->label = is_scalar($data['forwardLabel'] ?? null) ? (string) $data['forwardLabel'] : null;
            $type->reverseCode = is_scalar($data['reverseCode'] ?? null) ? (string) $data['reverseCode'] : null;
            $type->reverseLabel = is_scalar($data['reverseLabel'] ?? null) ? (string) $data['reverseLabel'] : null;
            $em->persist($type);
            $types[$code] = $type;
        }
        $em->flush();

        // Same raw-insert hot path as rows: link tables routinely dwarf the row count, so this is
        // where the ORM hurt most. Link types (above) stay on the ORM — a handful of rows.
        $conn = $em->getConnection();
        $links = new FolioBulkInserter($conn, 'link', self::LINK_COLUMNS);
        $count = 0;
        foreach (JsonlReader::open($linkFile) as $data) {
            $predicate = $this->requiredString($data, 'predicate', $linkFile);
            $type = $types[$predicate] ?? null;
            if (!$type instanceof LinkType) {
                throw new \RuntimeException(sprintf('Unknown link predicate "%s" in %s.', $predicate, $linkFile));
            }

            $subjectCore = $this->requiredString($data, 'subjectCore', $linkFile);
            $objectCore = $this->requiredString($data, 'objectCore', $linkFile);
            if ($subjectCore !== $type->leftCore || $objectCore !== $type->rightCore) {
                throw new \RuntimeException(sprintf(
                    'Link predicate "%s" expects %s→%s, got %s→%s in %s.',
                    $predicate,
                    $type->leftCore,
                    $type->rightCore,
                    $subjectCore,
                    $objectCore,
                    $linkFile,
                ));
            }

            $subjectId = $this->requiredString($data, 'subjectId', $linkFile);
            $objectId = $this->requiredString($data, 'objectId', $linkFile);
            $extras = array_diff_key($data, array_flip([
                'subjectCore',
                'subjectId',
                'predicate',
                'objectCore',
                'objectId',
            ]));

            // Order must match self::LINK_COLUMNS. Id mirrors the Link entity's xxh128 hash.
            $links->add([
                hash('xxh128', implode('|', [$type->id, $subjectId, $objectId])),
                $type->id,
                $type->leftCore,
                $subjectId,
                $type->rightCore,
                $objectId,
                $this->encodeJson($extras),
            ]);

            if (++$count % $batch === 0) {
                $sinceCommit = $this->maybeCheckpoint($conn, $sinceCommit + $batch, $links);
                $this->advanceIngestProgress($io, $batch);
            }
        }
        $links->flush();
        $this->advanceIngestProgress($io, $count % $batch);

        return ['count' => $count, 'skipped' => $links->skipped()];
    }

    /** @param array<string,mixed> $data */
    private function requiredString(array $data, string $field, string $file): string
    {
        $value = $data[$field] ?? null;
        if (!is_scalar($value) || trim((string) $value) === '') {
            throw new \RuntimeException(sprintf('Missing required field "%s" in %s.', $field, $file));
        }

        return trim((string) $value);
    }

    /**
     * @param array<string,mixed> $data
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function splitDtoData(string $dtoType, array $data): array
    {
        $class = $this->dtoTypeResolver->classForType($dtoType);
        if ($class === null || !class_exists($class)) {
            return [$data, []];
        }

        $dto = method_exists($class, 'fromNormalized') ? $class::fromNormalized($data) : new $class();
        $dtoData = method_exists($dto, 'toMeili')
            ? $dto->toMeili()
            : array_filter(get_object_vars($dto), static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
        unset($dtoData['unmapped']);

        $known = array_fill_keys(array_keys(get_object_vars($dto)), true);
        foreach (['class', 'content_type', 'contentType', 'dto_type', 'dtoType'] as $alias) {
            $known[$alias] = true;
        }

        $extras = [];
        foreach ($data as $key => $value) {
            if (!isset($known[$key])) {
                $extras[$key] = $value;
            }
        }

        return [$dtoData, $extras];
    }
}
