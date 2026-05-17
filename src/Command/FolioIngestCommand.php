<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Entity\{Core,Folio,Row};
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
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('dataset', InputArgument::OPTIONAL, 'Dataset key (e.g. mus/cleveland)')
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
            foreach ($this->registry->cores($dataset, $coreFilter) as $core) {
                $file = $this->registry->sourceFile($dataset, $core);
                if ($file === null) {
                    $io->warning(sprintf('No source for %s/%s — skipping.', $dataset->datasetKey, $core));
                    continue;
                }

                // Restore: copy bootstrap → destination, then open fresh DB.
                $this->folios->reset($dataset->datasetKey);
                $ctx = $this->folios->context($dataset->datasetKey);

                // Update metadata the service doesn't know about.
                $folio = $ctx->em->find(Folio::class, $ctx->folioCode);
                $folio->label = $dataset->label;
                $folio->datasetKey = $dataset->datasetKey;
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
                    $row->dtoData = $data;
                    $ctx->em->persist($row);

                    if (++$count % $batch === 0) {
                        $ctx->em->flush();
                        $ctx->em->clear();
                        // Re-attach after clear — fresh DB so find() is fast.
                        $folio = $ctx->em->find(Folio::class, $ctx->folioCode);
                        $coreEntity = $ctx->em->find(Core::class, $coreEntity->id);
                    }
                }

                $folio->rowCount      = $count;
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
        }

        return Command::SUCCESS;
    }
}
