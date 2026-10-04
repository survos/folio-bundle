<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Command;

use Survos\FolioBundle\Entity\Core;
use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Service\FolioRegistry;
use Survos\FolioBundle\Service\FolioService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('folio:migrate', 'Create or update folio SQLite schemas.')]
final class FolioMigrateCommand extends Command
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioRegistry $registry,
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('dataset', InputArgument::OPTIONAL, 'Dataset key (e.g. mus/cleveland)')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Convert metadata in one existing SQLite file without using the dataset registry')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Migrate all known datasets')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Migrate all datasets for a provider');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (($file = $input->getOption('file')) !== null) {
            if ($input->getArgument('dataset') || $input->getOption('all') || $input->getOption('provider')) {
                throw new \InvalidArgumentException('--file cannot be combined with dataset selection.');
            }
            if (!is_file($file)) { throw new \InvalidArgumentException('Folio file not found: '.$file); }
            $pdo = new \PDO('sqlite:'.$file, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='folio'")->fetchColumn()) {
                throw new \InvalidArgumentException('Not a folio: '.$file);
            }
            (new \Survos\Folio\PropertyStore($pdo))->migrate();
            $io->success('Folio metadata converted: '.$file);
            return Command::SUCCESS;
        }

        $datasets = $this->registry->datasets(
            datasetKey: (string) ($input->getArgument('dataset') ?? '') ?: null,
            provider: (string) ($input->getOption('provider') ?? '') ?: null,
            all: (bool) $input->getOption('all'),
        );

        if ($datasets === []) {
            $io->warning('No datasets found. Run data:scan-datasets first.');
            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($datasets as $dataset) {
            // Create from bootstrap if new; update schema in-place if existing.
            if (!is_file($this->folios->path($dataset->datasetKey))) {
                $this->folios->reset($dataset->datasetKey);
            }
            $ctx = $this->folios->context($dataset->datasetKey, true);

            // Update metadata fields the service doesn't know about.
            $folio = $ctx->em->find(Folio::class, $ctx->folioCode);
            // Conversion preserves the existing label and its ownership.
            $folio->datasetKey = $dataset->datasetKey;

            // Backfill Core::$geoCount for folios ingested before that column existed — the
            // self-healing schema (FolioService::context() -> FolioSchemaManager::update())
            // ALTERs the column in but leaves it at its 0 default; only a re-ingest or this
            // recomputes the real value. Safe/cheap to run every time: one COUNT per core.
            foreach ($ctx->em->getRepository(Core::class)->findBy(['folio' => $folio]) as $core) {
                $core->geoCount = (int) $ctx->em->getConnection()->executeQuery(
                    "SELECT COUNT(*) FROM item WHERE core_id = ?
                        AND json_extract(dto_data, '\$.latitude') IS NOT NULL
                        AND json_extract(dto_data, '\$.longitude') IS NOT NULL",
                    [$core->id],
                )->fetchOne();
            }

            $ctx->em->flush();

            $io->text(sprintf('  ✓ %s → %s', $dataset->datasetKey, $ctx->path));
            $count++;
        }

        $io->success(sprintf('%d folio(s) migrated.', $count));
        return Command::SUCCESS;
    }
}
