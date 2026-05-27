<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('folio:ai:import-claims', 'Import OpenAI batch output into folio-local claims.')]
final class FolioAiClaimImporter
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioAiArtifactPaths $paths,
        private readonly FolioAiBatchPreparer $preparer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Folio code, e.g. fortepan/hu')] string $folioCode,
        #[Option('Core code inside the folio')] string $core = 'obj',
        #[Option('AI task used to create the output file')] string $task = FolioAiPromptBuilder::IMAGE_ENRICH,
        #[Option('Downloaded OpenAI batch output JSONL file')] ?string $input = null,
        #[Option('Replace existing claims for each imported row/source')] bool $replace = true,
        #[Option('Do not write changes')] bool $dryRun = false,
    ): int {
        $result = $this->import($folioCode, $core, $task, $input, $replace, $dryRun);
        $io->success(sprintf('Imported %d claim(s) for %d row(s); %d failed line(s).', $result['claims'], $result['rows'], $result['failed']));

        return Command::SUCCESS;
    }

    /** @return array{rows:int,claims:int,failed:int,input:string} */
    public function import(string $folioCode, string $coreCode = 'obj', string $task = FolioAiPromptBuilder::IMAGE_ENRICH, ?string $input = null, bool $replace = true, bool $dryRun = false): array
    {
        $input ??= $this->paths->outputPath($folioCode, $coreCode, $task);
        if (!is_file($input)) {
            throw new \RuntimeException(sprintf('Batch output file not found: %s', $input));
        }

        $source = $this->preparer->sourceForTask($task);
        $ctx = $this->folios->context($folioCode);
        $connection = $ctx->em->getConnection();
        $rows = 0;
        $claims = 0;
        $failed = 0;

        $handle = fopen($input, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Unable to read "%s".', $input));
        }

        if (!$dryRun) {
            $connection->beginTransaction();
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);
                if (!is_array($record)) {
                    $failed++;
                    continue;
                }

                $itemId = (string) ($record['custom_id'] ?? '');
                $content = $this->responseContent($record);
                if ($itemId === '' || $content === null) {
                    $failed++;
                    continue;
                }

                $payload = json_decode($content, true);
                if (!is_array($payload)) {
                    $failed++;
                    continue;
                }

                $claimRows = $this->claimRows($itemId, $payload, $source, $record);
                if ($claimRows === []) {
                    continue;
                }

                if (!$dryRun && $replace) {
                    $connection->executeStatement('DELETE FROM claim WHERE item_id = :itemId AND source = :source', ['itemId' => $itemId, 'source' => $source]);
                }

                foreach ($claimRows as $claimRow) {
                    if (!$dryRun) {
                        $this->insertClaim($connection, $claimRow);
                    }
                    $claims++;
                }
                $rows++;
            }

            if (!$dryRun) {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            if (!$dryRun) {
                $connection->rollBack();
            }
            throw $e;
        } finally {
            fclose($handle);
        }

        return ['rows' => $rows, 'claims' => $claims, 'failed' => $failed, 'input' => $input];
    }

    /** @param array<string,mixed> $record */
    private function responseContent(array $record): ?string
    {
        $content = $record['response']['body']['choices'][0]['message']['content'] ?? null;
        return is_string($content) && trim($content) !== '' ? $content : null;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $record
     * @return list<array<string,mixed>>
     */
    private function claimRows(string $itemId, array $payload, string $source, array $record): array
    {
        $rows = [];
        $agent = $record['response']['body']['model'] ?? $record['metadata']['model'] ?? null;
        $baseMeta = ['customId' => $record['custom_id'] ?? $itemId, 'sourcePayloadKeys' => array_keys($payload)];

        foreach ($this->fieldPredicateMap() as $field => $predicate) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }
            foreach ($this->values($payload[$field]) as $value) {
                $rows[] = $this->claimRow($itemId, $predicate, $value, $source, $payload['confidence'] ?? null, is_string($agent) ? $agent : null, $baseMeta);
            }
        }

        $claims = $payload['claims'] ?? [];
        if (is_array($claims)) {
            foreach ($claims as $claim) {
                if (!is_array($claim)) {
                    continue;
                }
                $predicate = $claim['predicate'] ?? null;
                if (!is_string($predicate) || $predicate === '') {
                    continue;
                }
                foreach ($this->values($claim['value'] ?? null) as $value) {
                    $meta = $baseMeta;
                    if (isset($claim['basis'])) {
                        $meta['basis'] = $claim['basis'];
                    }
                    $rows[] = $this->claimRow($itemId, $predicate, $value, $source, $claim['confidence'] ?? ($payload['confidence'] ?? null), is_string($agent) ? $agent : null, $meta);
                }
            }
        }

        return $rows;
    }

    /** @return array<string,string> */
    private function fieldPredicateMap(): array
    {
        return [
            'title' => 'dcterms:title',
            'description' => 'dcterms:description',
            'denseSummary' => 'ai:denseSummary',
            'dense_summary' => 'ai:denseSummary',
            'keywords' => 'dcterms:subject',
            'subjects' => 'dcterms:subject',
            'people' => 'foaf:Person',
            'places' => 'dcterms:spatial',
            'organizations' => 'ai:organization',
            'organisations' => 'ai:organization',
            'dateText' => 'dcterms:date',
        ];
    }

    /** @return list<string> */
    private function values(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            return [(string) $value];
        }

        $out = [];
        foreach ($value as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            $out[] = is_scalar($item) ? (string) $item : json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return array_values(array_filter($out, static fn (?string $item): bool => $item !== null && $item !== ''));
    }

    /** @param array<string,mixed> $meta */
    private function claimRow(string $itemId, string $predicate, string $value, string $source, mixed $confidence, ?string $agent, array $meta): array
    {
        $confidence = is_numeric($confidence) ? (float) $confidence : null;
        if ($confidence !== null && $confidence > 1) {
            $confidence /= 100;
        }

        return [
            'id' => hash('xxh128', implode('|', [$itemId, $predicate, $source, $value])),
            'item_id' => $itemId,
            'predicate' => $predicate,
            'value' => $value,
            'source' => $source,
            'confidence' => $confidence,
            'agent' => $agent,
            'claimed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'meta' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @param array<string,mixed> $row */
    private function insertClaim(Connection $connection, array $row): void
    {
        $connection->executeStatement(<<<'SQL'
INSERT OR REPLACE INTO claim (id, item_id, predicate, value, source, confidence, agent, claimed_at, meta)
VALUES (:id, :item_id, :predicate, :value, :source, :confidence, :agent, :claimed_at, :meta)
SQL, $row);
    }
}
