<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\FolioBundle\Entity\{Core,Folio,Link,LinkType,Row};
use Survos\FolioBundle\Event\FolioIngestFinishedEvent;
use Survos\FolioBundle\Service\{FolioDtoTypeResolver,FolioRegistry,FolioService,FolioSummaryService};
use Survos\JsonlBundle\IO\JsonlReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsCommand('folio:ingest', 'Restore normalized JSONL into a folio (replaces existing data).')]
final class FolioIngestCommand extends Command
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioRegistry $registry,
        private readonly FolioDtoTypeResolver $dtoTypeResolver,
        private readonly FolioSummaryService $summaryService,
        private readonly DataPaths $dataPaths,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('dataset', InputArgument::OPTIONAL, 'Dataset key (e.g. harvest/cleveland, md/collection-id, dc/05747667f)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Ingest all datasets that have source data')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Ingest all datasets for a provider')
            ->addOption('core', null, InputOption::VALUE_REQUIRED, 'Core name (default: from DatasetInfo or "obj")')
            ->addOption('id-field', null, InputOption::VALUE_REQUIRED, 'Identifier field', 'id')
            ->addOption('label-field', null, InputOption::VALUE_REQUIRED, 'Label field', 'label')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Flush batch size', 500);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $datasets = $this->registry->datasets(
            datasetKey: (string) ($input->getArgument('dataset') ?? '') ?: null,
            provider: (string) ($input->getOption('provider') ?? '') ?: null,
            all: (bool) $input->getOption('all'),
            requireSource: true,
        );

        if ($datasets === []) {
            $io->warning('No datasets with source data found. Run data:scan-datasets first.');
            return Command::SUCCESS;
        }

        $batch = max(1, (int) $input->getOption('batch'));
        $coreFilter = (string) ($input->getOption('core') ?? '') ?: null;
        $idField = (string) $input->getOption('id-field');
        $labelField = (string) $input->getOption('label-field');

        foreach ($datasets as $dataset) {
            $sources = [];
            foreach ($this->registry->cores($dataset, $coreFilter) as $core) {
                $file = $this->registry->sourceFile($dataset, $core);
                if ($file === null) {
                    $io->warning(sprintf('No source for %s/%s — skipping.', $dataset->datasetKey, $core));
                    continue;
                }
                $sources[$core] = $file;
            }

            if ($sources === []) {
                continue;
            }

            // Restore once per dataset, then ingest every selected core into the same folio.
            $this->folios->reset($dataset->datasetKey);
            $ctx = $this->folios->context($dataset->datasetKey, ensureSchema: true);

            // Update metadata the service doesn't know about.
            $folio = $ctx->em->find(Folio::class, $ctx->folioCode);
            $folio->label = $dataset->label;
            $folio->datasetKey = $dataset->datasetKey;
            $ctx->em->flush();

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
                    }
                }

                $totalCount += $count;
                $coreEntity->rowCount = $count;
                $coreEntity->fieldSummary = $this->registry->populatedFields($dataset, $core);
                $ctx->em->flush();
                $summary = $this->summaryService->summarize($ctx->path);

                $this->dispatcher?->dispatch(new FolioIngestFinishedEvent(
                    datasetKey: $dataset->datasetKey,
                    core: $core,
                    dbFile: $ctx->path,
                    sourceFile: $file,
                    summary: $summary,
                ));

                $io->success(sprintf(
                    'Ingested %d rows → %s/%s from %s',
                    $count, $dataset->datasetKey, $core, basename($file),
                ));
            }

            $linkCount = $this->ingestLinks($ctx->em, $folio, $dataset->datasetKey, $batch);
            if ($linkCount > 0) {
                $io->success(sprintf(
                    'Ingested %d link(s) → %s from link.jsonl',
                    $linkCount,
                    $dataset->datasetKey,
                ));
            }

            $folio = $ctx->em->find(Folio::class, $ctx->folioCode);
            $folio->rowCount = $totalCount;
            $ctx->em->flush();
        }

        return Command::SUCCESS;
    }


    private function ingestLinks(EntityManagerInterface $em, Folio $folio, string $datasetKey, int $batch): int
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
            }
        }
        $em->flush();

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
