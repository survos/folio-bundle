<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('folio:ai:prepare', 'Prepare an OpenAI batch JSONL file from folio rows.')]
final class FolioAiBatchPreparer
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioAiArtifactPaths $paths,
        private readonly FolioAiPromptBuilder $promptBuilder,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Folio code, e.g. fortepan/hu')] string $folioCode,
        #[Option('Core code inside the folio')] string $core = 'obj',
        #[Option('AI task: image_enrich or dense_summary')] string $task = FolioAiPromptBuilder::IMAGE_ENRICH,
        #[Option('Max rows to include')] int $limit = 1000,
        #[Option('Only include one dtoType')] ?string $dtoType = null,
        #[Option('OpenAI model name')] string $model = 'gpt-4o-mini',
        #[Option('Overwrite existing batch input and manifest')] bool $force = false,
        #[Option('Include rows that already have claims for this task source')] bool $includeExisting = false,
        #[Option('Image detail sent to OpenAI')] string $imageDetail = 'low',
    ): int {
        $result = $this->prepare($folioCode, $core, $task, $limit, $dtoType, $model, $force, $includeExisting, $imageDetail);
        $io->success(sprintf('Prepared %d row(s) in %s', $result['count'], $result['inputPath']));
        $io->writeln(sprintf('Manifest: %s', $result['manifestPath']));

        return Command::SUCCESS;
    }

    /** @return array{count:int,inputPath:string,manifestPath:string,outputPath:string} */
    public function prepare(
        string $folioCode,
        string $coreCode = 'obj',
        string $task = FolioAiPromptBuilder::IMAGE_ENRICH,
        int $limit = 1000,
        ?string $dtoType = null,
        string $model = 'gpt-4o-mini',
        bool $force = false,
        bool $includeExisting = false,
        string $imageDetail = 'low',
    ): array {
        $this->assertTask($task);

        $inputPath = $this->paths->inputPath($folioCode, $coreCode, $task);
        $manifestPath = $this->paths->manifestPath($folioCode, $coreCode, $task);
        $outputPath = $this->paths->outputPath($folioCode, $coreCode, $task);
        if (!$force && (is_file($inputPath) || is_file($manifestPath))) {
            throw new \RuntimeException(sprintf('Batch files already exist for %s/%s/%s. Use --force to overwrite.', $folioCode, $coreCode, $task));
        }

        $dir = dirname($inputPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Unable to create AI artifact directory "%s".', $dir));
        }

        $ctx = $this->folios->context($folioCode);
        $source = $this->sourceForTask($task);
        $handle = fopen($inputPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to write "%s".', $inputPath));
        }

        $count = 0;
        $skippedNoImage = 0;
        try {
            foreach ($this->candidateRows($ctx->em->getConnection(), $coreCode, $dtoType, $source, $includeExisting) as $row) {
                if ($task === FolioAiPromptBuilder::IMAGE_ENRICH) {
                    $dtoData = $this->decode($row['dto_data'] ?? null);
                    if ($this->promptBuilder->imageUrl($dtoData) === null) {
                        $skippedNoImage++;
                        continue;
                    }
                }

                $line = $this->promptBuilder->requestLine($row, $task, $model, $source, $imageDetail);
                fwrite($handle, json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                $count++;
                if ($count >= $limit) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        $manifest = [
            'folioCode' => $folioCode,
            'core' => $coreCode,
            'task' => $task,
            'source' => $source,
            'model' => $model,
            'count' => $count,
            'skippedNoImage' => $skippedNoImage,
            'createdAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'inputPath' => $inputPath,
            'outputPath' => $outputPath,
            'providerBatchId' => null,
            'selection' => [
                'dtoType' => $dtoType,
                'includeExisting' => $includeExisting,
            ],
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return ['count' => $count, 'inputPath' => $inputPath, 'manifestPath' => $manifestPath, 'outputPath' => $outputPath];
    }

    public function sourceForTask(string $task): string
    {
        $this->assertTask($task);

        return match ($task) {
            FolioAiPromptBuilder::IMAGE_ENRICH => 'ai:image_enrich@1',
            FolioAiPromptBuilder::DENSE_SUMMARY => 'ai:dense_summary@1',
        };
    }

    private function assertTask(string $task): void
    {
        if (!in_array($task, [FolioAiPromptBuilder::IMAGE_ENRICH, FolioAiPromptBuilder::DENSE_SUMMARY], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported folio AI task "%s".', $task));
        }
    }

    /** @return \Generator<int,array<string,mixed>> */
    private function candidateRows(Connection $connection, string $coreCode, ?string $dtoType, string $source, bool $includeExisting): \Generator
    {
        $sql = <<<'SQL'
SELECT i.id, i.local_id, i.label, i.dto_type, i.dto_data, i.extras
FROM item i
JOIN core c ON c.id = i.core_id
WHERE c.code = :coreCode
SQL;
        $params = ['coreCode' => $coreCode];
        if ($dtoType !== null && $dtoType !== '') {
            $sql .= ' AND i.dto_type = :dtoType';
            $params['dtoType'] = $dtoType;
        }
        if (!$includeExisting) {
            $sql .= ' AND NOT EXISTS (SELECT 1 FROM claim cl WHERE cl.item_id = i.id AND cl.source = :source)';
            $params['source'] = $source;
        }
        $sql .= ' ORDER BY i.id';

        $result = $connection->executeQuery($sql, $params);
        while (($row = $result->fetchAssociative()) !== false) {
            yield $row;
        }
    }

    /** @return array<string,mixed>|null */
    private function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
