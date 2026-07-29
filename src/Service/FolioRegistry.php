<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\JsonlBundle\Sqlite\SidecarDb;
use Symfony\Component\DependencyInjection\Attribute\Target;

final class FolioRegistry
{
    // Every entry here has its OWN dedicated ingestion path elsewhere in FolioIngestService
    // (ingestTerms/ingestLinks/ingestClaims/ingestPages) -- excluded from generic row-core
    // auto-discovery because they're relational/sidecar data, not because nothing reads them.
    // 'story' is different: it's the canonical, nested story-contract document (story-contract's
    // own story.v1 shape, sitting beside obj.jsonl in the same norm/ dir) read directly via
    // FolioRegistry::sourceFile()/StoryFinder (survos/exhibit-bundle), NOT through folio's generic
    // row ingest -- excluded for the same reason 'page' is: it has its own reader, not because
    // there's no ContentType for it. The flattened, DERIVED projections of the same data --
    // story.jsonl's row-per-tour form now carries a real ContentType::STORY and IS ingested (not
    // excluded); stop.jsonl (ContentType::STOP, one row per narrative unit) is new and always was
    // a real core. See md's CuratescapeProvider::storyNormalize() and
    // ~/sites/rut/docs/PLAN-folio-data-layer.md.
    private const NON_ROW_JSONL = ['termSet', 'term', 'linkType', 'link', 'claim', 'claims', 'page'];

    public function __construct(
        private readonly DataPaths $dataPaths,
        // Optional: the bundle auto-wires the dataset registry EM only when dataset-bundle is
        // loaded (ignoreOnInvalid reference). It's null in a bare app that just pulls + displays
        // folios — only datasets() needs it; every other method works off a DatasetInfo + DataPaths.
        #[Target('doctrine.orm.dataset_entity_manager')]
        private readonly ?EntityManagerInterface $datasetEntityManager = null,
    ) {}

    /** @return list<DatasetInfo> */
    public function datasets(
        ?string $datasetKey = null,
        ?string $provider = null,
        bool $all = false,
        bool $requireSource = false,
    ): array {
        if ($this->datasetEntityManager === null) {
            throw new \LogicException(
                'FolioRegistry::datasets() needs the dataset registry (dataset-bundle), which is not loaded. '
                . 'A bare folio app pulls and displays folios directly, without listing the dataset registry.'
            );
        }
        $repo = $this->datasetEntityManager->getRepository(DatasetInfo::class);

        if ($datasetKey !== null && $datasetKey !== '') {
            // DatasetInfo PKs use slash (e.g. "mus/aust"); sanitizeDatasetKey() returns dash format.
            $ref     = $this->dataPaths->parseDatasetRef($datasetKey);
            $dataset = $repo->find($ref['provider'] . '/' . $ref['code']);
            return $dataset instanceof DatasetInfo ? [$dataset] : [];
        }

        $qb = $repo->createQueryBuilder('d')->orderBy('d.datasetKey', 'ASC');

        if ($provider !== null && $provider !== '') {
            $qb->andWhere('d.datasetKey LIKE :prefix')
                ->setParameter('prefix', strtolower(trim($provider)) . '/%');
        } elseif (!$all) {
            throw new \InvalidArgumentException('Pass a dataset key, --provider, or --all.');
        }

        return array_values(array_filter(
            $qb->getQuery()->getResult(),
            fn (mixed $d): bool => $d instanceof DatasetInfo
                && (!$requireSource || $this->hasFolioSource($d)),
        ));
    }

    public function hasFolioSource(DatasetInfo $dataset): bool
    {
        return $this->sourceFile($dataset) !== null;
    }

    public function sourceFile(DatasetInfo $dataset, string $core = 'obj'): ?string
    {
        $normalized = $this->dataPaths->stageDir($dataset->datasetKey, 'normalized') . '/' . $core . '.jsonl';

        // Prefer the enriched (_folio) stage, but only when it actually has rows AND is at least as
        // recent as the normalized source. A 0-byte enriched file from a failed/interrupted
        // `dataset:enrich` must never shadow a populated normalized source — otherwise folio:build
        // ingests 0 rows while pages still load (every page orphaned). Likewise a re-normalize (e.g.
        // a listener fix) must not be shadowed by an enriched artifact built from the old normalized
        // data — otherwise the fix is invisible to folio:build until someone remembers to delete or
        // rebuild `_folio/<core>.jsonl` by hand.
        $enriched = $this->dataPaths->enrichFile($dataset->datasetKey, $core);
        if (
            is_file($enriched) && filesize($enriched) > 0
            && (!is_file($normalized) || filemtime($enriched) >= filemtime($normalized))
        ) {
            return $enriched;
        }

        if (is_file($normalized)) {
            return $normalized;
        }

        return null;
    }

    /**
     * Returns field names that have at least one non-null value in the source SQLite sidecar.
     *
     * @return string[]|null null if no readable sidecar stats exist
     */
    public function populatedFields(DatasetInfo $dataset, string $core = 'obj'): ?array
    {
        $source = $this->sourceFile($dataset, $core);
        if ($source === null) {
            return null;
        }

        $dbPath = $source . '.db';
        if (!is_file($dbPath)) {
            return null;
        }

        try {
            $populated = [];
            foreach ((new SidecarDb($dbPath))->loadFieldStats() as $stats) {
                $name = $stats['path'] ?? null;
                if (is_string($name) && $name !== '' && (int) ($stats['non_null'] ?? 0) > 0) {
                    $populated[] = $name;
                }
            }

            return $populated ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    public function cores(DatasetInfo $dataset, ?string $core = null): array
    {
        if ($core !== null && $core !== '') {
            return in_array($core, self::NON_ROW_JSONL, true) ? [] : [$core];
        }

        $cores = $dataset->cores !== [] ? array_values(array_filter(
            $dataset->cores,
            static fn (string $name): bool => !in_array($name, self::NON_ROW_JSONL, true),
        )) : ['obj'];

        $normalizeDir = $this->dataPaths->stageDir($dataset->datasetKey, 'normalized');
        if (is_dir($normalizeDir)) {
            foreach (new \DirectoryIterator($normalizeDir) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'jsonl') {
                    continue;
                }

                $name = $file->getBasename('.jsonl');
                if (in_array($name, self::NON_ROW_JSONL, true)) {
                    continue;
                }

                $cores[] = $name;
            }
        }

        sort($cores);
        return array_values(array_unique($cores));
    }
}
