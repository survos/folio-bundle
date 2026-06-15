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
    private const NON_ROW_JSONL = ['termSet', 'term', 'linkType', 'link', 'claim', 'claims', 'page'];

    public function __construct(
        private readonly DataPaths $dataPaths,
        #[Target('dataset.entity_manager')] private readonly EntityManagerInterface $datasetEntityManager,
    ) {}

    /** @return list<DatasetInfo> */
    public function datasets(
        ?string $datasetKey = null,
        ?string $provider = null,
        bool $all = false,
        bool $requireSource = false,
    ): array {
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
        $enriched = $this->dataPaths->enrichFile($dataset->datasetKey, $core);
        if (is_file($enriched)) {
            return $enriched;
        }

        $normalized = $this->dataPaths->stageDir($dataset->datasetKey, 'normalized') . '/' . $core . '.jsonl';
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
            return [$core];
        }

        $cores = $dataset->cores !== [] ? $dataset->cores : ['obj'];

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
