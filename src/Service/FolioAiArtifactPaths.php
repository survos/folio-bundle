<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\Persistence\ManagerRegistry;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Service\DataPaths;

final class FolioAiArtifactPaths
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly DataPaths $dataPaths,
    ) {
    }

    public function aiDir(string $datasetKey): string
    {
        $root = $this->datasetRootFromRegistry($datasetKey) ?? $this->dataPaths->datasetDir($datasetKey);
        $aiStage = basename($this->dataPaths->stageDir($datasetKey, 'ai'));

        return rtrim($root, '/') . '/' . $aiStage;
    }

    public function inputPath(string $datasetKey, string $coreCode, string $task): string
    {
        return sprintf('%s/%s.%s.batch.input.jsonl', $this->aiDir($datasetKey), $coreCode, $task);
    }

    public function manifestPath(string $datasetKey, string $coreCode, string $task): string
    {
        return sprintf('%s/%s.%s.batch.json', $this->aiDir($datasetKey), $coreCode, $task);
    }

    public function outputPath(string $datasetKey, string $coreCode, string $task): string
    {
        return sprintf('%s/%s.%s.batch.output.jsonl', $this->aiDir($datasetKey), $coreCode, $task);
    }

    private function datasetRootFromRegistry(string $datasetKey): ?string
    {
        $manager = $this->doctrine->getManagerForClass(DatasetInfo::class);
        if ($manager === null) {
            return null;
        }

        $dataset = $manager->find(DatasetInfo::class, $datasetKey);
        if (!$dataset instanceof DatasetInfo) {
            return null;
        }

        foreach ([$dataset->normalizedPath, $dataset->profilePath, $dataset->rawPath, $dataset->metaPath] as $path) {
            $root = $this->rootFromRegisteredPath($path);
            if ($root !== null) {
                return $root;
            }
        }

        return null;
    }

    private function rootFromRegisteredPath(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $path = rtrim($path, '/');
        $dir = is_dir($path) ? $path : dirname($path);

        return dirname($dir);
    }
}
