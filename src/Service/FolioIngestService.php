<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\FolioBundle\Entity\{Core,Folio,Link,LinkType,Row,Term,TermSet};
use Survos\FolioBundle\Event\FolioIngestFinishedEvent;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\Service\JsonlCountService;
use Symfony\Component\Console\Helper\ProgressBar;
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
     * @return array{rows:int,terms:int,links:int,cores:array<string,array{count:int,file:string}>}
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

        $folio = $ctx->em->find(Folio::class, $ctx->folioCode);
        $folio->label = $dataset->label;
        $folio->datasetKey = $dataset->datasetKey;
        $ctx->em->flush();

        // Seed the bar from sidecar row counts (no full scan) when an $io is supplied. It spans every
        // row-bearing phase — cores, terms, links — so one bar covers the whole ingest. (claims and
        // translations will join this list; the per-phase ingest calls below all advance the same bar.)
        $progress = null;
        if ($io !== null) {
            $normalizeDir = $this->dataPaths->stageDir($dataset->datasetKey, 'normalize');
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
            $progress = $io->createProgressBar($expected);
            $progress->setFormat(" %current%/%max% [%bar%] %percent:3s%%  %elapsed:6s%/%estimated:-6s%  %memory:6s%");
            $progress->setRedrawFrequency(max(1, $batch));
            $progress->start();
        }

        $coreResults = [];
        $totalCount = 0;
        foreach ($sources as $core => $file) {
            $coreEntity = new Core($folio, $core);
            $ctx->em->persist($coreEntity);
            $ctx->em->flush();

            $count = 0;
            foreach (JsonlReader::open($file) as $data) {
                $localId = (string) ($data[$idField] ?? $data['@id'] ?? $data['_id'] ?? '');
                if ($localId === '') {
                    throw new \RuntimeException(sprintf('Missing id near row %d in %s.', $count + 1, $file));
                }

                $row = new Row($coreEntity, $localId);
                $row->label = isset($data[$labelField])
                    ? (string) $data[$labelField]
                    : (isset($data['title']) ? (string) $data['title'] : $localId);
                $row->dtoType = $this->dtoTypeResolver->typeFromPayload($data);
                try {
                    [$row->dtoData, $row->extras] = $this->splitDtoData($row->dtoType, $data);
                } catch (\TypeError) {
                    $row->dtoData = $data;
                }
                $ctx->em->persist($row);

                if (++$count % $batch === 0) {
                    $ctx->em->flush();
                    $ctx->em->clear();
                    // Re-attach after clear — fresh DB so find() is fast.
                    $folio = $ctx->em->find(Folio::class, $ctx->folioCode);
                    $coreEntity = $ctx->em->find(Core::class, $coreEntity->id);
                    $progress?->advance($batch);
                }
            }

            $totalCount += $count;
            $coreEntity->rowCount = $count;
            $coreEntity->fieldSummary = $this->registry->populatedFields($dataset, $core);
            $ctx->em->flush();
            $progress?->advance($count % $batch);

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

        $termCount = $this->ingestTerms($ctx->em, $folio, $dataset->datasetKey, $batch, $progress);
        $linkCount = $this->ingestLinks($ctx->em, $folio, $dataset->datasetKey, $batch, $progress);

        $progress?->finish();
        if ($io !== null) {
            $io->newLine(2);
        }

        $folio = $ctx->em->find(Folio::class, $ctx->folioCode);
        $folio->rowCount = $totalCount;
        $ctx->em->flush();

        return ['rows' => $totalCount, 'terms' => $termCount, 'links' => $linkCount, 'cores' => $coreResults];
    }

    private function ingestTerms(EntityManagerInterface $em, Folio $folio, string $datasetKey, int $batch, ?ProgressBar $progress = null): int
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
        $terms = [];
        $count = 0;
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
            $terms[$term->id] = $term;

            if (++$count % $batch === 0) {
                $em->flush();
                $progress?->advance($batch);
            }
        }
        $em->flush();
        $progress?->advance($count % $batch);

        foreach ($pendingParents as $termId => $parentId) {
            $term = $terms[$termId] ?? $em->find(Term::class, $termId);
            $parent = $terms[$parentId] ?? $em->find(Term::class, $parentId);
            if ($term instanceof Term && $parent instanceof Term) {
                $term->parent = $parent;
            }
        }
        $em->flush();

        return $count;
    }

    private function ingestLinks(EntityManagerInterface $em, Folio $folio, string $datasetKey, int $batch, ?ProgressBar $progress = null): int
    {
        $normalizeDir = $this->dataPaths->stageDir($datasetKey, 'normalize');
        $linkTypeFile = $normalizeDir . '/linkType.jsonl';
        $linkFile = $normalizeDir . '/link.jsonl';

        if (!is_file($linkTypeFile) || !is_file($linkFile)) {
            return 0;
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

            $link = new Link(
                $type,
                $this->requiredString($data, 'subjectId', $linkFile),
                $this->requiredString($data, 'objectId', $linkFile),
            );
            $link->extras = array_diff_key($data, array_flip([
                'subjectCore',
                'subjectId',
                'predicate',
                'objectCore',
                'objectId',
            ]));
            $em->persist($link);

            if (++$count % $batch === 0) {
                $em->flush();
                $progress?->advance($batch);
            }
        }
        $em->flush();
        $progress?->advance($count % $batch);

        return $count;
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
