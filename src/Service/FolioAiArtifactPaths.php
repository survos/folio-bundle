<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Path\DataPaths;

final class FolioAiArtifactPaths
{
    public function __construct(
        private readonly DataPaths $dataPaths,
    ) {
    }

    public function aiDir(string $datasetKey): string
    {
        // AI artifacts live in the durable vault (vault/<provider>/<code>/ai), not the work tree.
        return $this->dataPaths->aiDir($datasetKey);
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

}
