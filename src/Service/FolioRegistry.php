<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Enum\Stage;
use Survos\DatasetBundle\Service\DataPaths;
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

        if ($core === 'obj' && $dataset->normalizedPath !== null && is_file($dataset->normalizedPath)) {
            return $dataset->normalizedPath;
        }

        $normalized = $this->dataPaths->stageDir($dataset->datasetKey, 'normalized') . '/' . $core . '.jsonl';
        if (is_file($normalized)) {
            return $normalized;
        }

        return null;
    }

    /**
     * Returns field names that have at least one non-null value in the normalized profile.
     * @return string[]|null null if no profile found
     */
    public function populatedFields(DatasetInfo $dataset, string $core = 'obj'): ?array
    {
        // Profile is a sidecar next to the normalized JSONL (norm/<core>.profile.json).
        $profilePath = $this->dataPaths->stageDir($dataset->datasetKey, Stage::Normalize) . "/$core.profile.json";
        if (!is_file($profilePath) && $core === 'obj' && $dataset->profilePath !== null && is_file($dataset->profilePath)) {
            $profilePath = $dataset->profilePath;
        }
        if (!is_file($profilePath)) {
            return null;
        }
        try {
            $profile     = json_decode(file_get_contents($profilePath), true, 512, JSON_THROW_ON_ERROR);
            $recordCount = (int) ($profile['recordCount'] ?? 0);
            $populated   = [];
            foreach ($profile['fields'] ?? [] as $name => $stats) {
                if ($recordCount > 0 && ((int) ($stats['nulls'] ?? $recordCount)) < $recordCount) {
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
