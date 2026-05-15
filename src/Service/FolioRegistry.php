<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DataBundle\Entity\DatasetInfo;
use Survos\DataBundle\Service\DataPaths;

final class FolioRegistry
{
    public function __construct(
        private readonly EntityManagerInterface $defaultEntityManager,
        private readonly DataPaths $dataPaths,
    ) {}

    /** @return list<DatasetInfo> */
    public function datasets(
        ?string $datasetKey = null,
        ?string $provider = null,
        bool $all = false,
        bool $requireSource = false,
    ): array {
        $repo = $this->defaultEntityManager->getRepository(DatasetInfo::class);

        if ($datasetKey !== null && $datasetKey !== '') {
            $dataset = $repo->find($this->dataPaths->sanitizeDatasetKey($datasetKey));
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

        if ($core === 'obj' && $dataset->normalizedPath !== null && is_file($dataset->normalizedPath)) {
            return $dataset->normalizedPath;
        }

        $normalized = $this->dataPaths->stageDir($dataset->datasetKey, 'normalized') . '/' . $core . '.jsonl';
        if (is_file($normalized)) {
            return $normalized;
        }

        return null;
    }

    /** @return list<string> */
    public function cores(DatasetInfo $dataset, ?string $core = null): array
    {
        if ($core !== null && $core !== '') {
            return [$core];
        }

        $cores = $dataset->cores !== [] ? $dataset->cores : ['obj'];
        sort($cores);
        return array_values(array_unique($cores));
    }
}
