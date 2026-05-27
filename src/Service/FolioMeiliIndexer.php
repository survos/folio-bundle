<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\MeiliBundle\Service\IndexNameResolver;
use Survos\MeiliBundle\Service\MeiliNdjsonUploader;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('folio:meili:populate', 'Populate Meilisearch from folio rows.')]
final class FolioMeiliIndexer
{
    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioMeiliDocumentBuilder $documentBuilder,
        private readonly MeiliService $meili,
        private readonly MeiliNdjsonUploader $uploader,
        private readonly IndexNameResolver $indexNameResolver,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Folio code, e.g. dc/4168b346w')] string $folioCode,
        #[Option('Core code inside the folio')] string $core = 'obj',
        #[Option('Only index one dtoType')] ?string $dtoType = null,
        #[Option('Max rows to index')] ?int $limit = null,
        #[Option('Reset index before indexing')] bool $reset = false,
        #[Option('Wait for Meilisearch task completion')] bool $wait = false,
        #[Option('Primary key field')] string $pk = 'id',
        #[Option('Override index base name')] ?string $index = null,
    ): int {
        $result = $this->index(
            folioCode: $folioCode,
            coreCode: $core,
            dtoType: $dtoType,
            limit: $limit,
            reset: $reset,
            wait: $wait,
            primaryKey: $pk,
            baseName: $index,
        );

        $io->success(sprintf(
            'Indexed %d folio row(s) into %s%s',
            $result['count'],
            $result['indexUid'],
            $result['taskUid'] !== null ? sprintf(' (task %d)', $result['taskUid']) : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{indexUid:string,count:int,taskUid:int|null}
     */
    public function index(
        string $folioCode,
        string $coreCode = 'obj',
        ?string $dtoType = null,
        ?int $limit = null,
        bool $reset = false,
        bool $wait = false,
        string $primaryKey = 'id',
        ?string $baseName = null,
    ): array {
        $ctx = $this->folios->context($folioCode);
        $baseName ??= $this->baseName($folioCode, $coreCode);
        $indexUid = $this->indexNameResolver->uidForRaw($baseName);

        if ($reset) {
            $this->meili->purge($indexUid);
        }

        $index = $this->meili->getOrCreateIndex($indexUid, primaryKey: $primaryKey, autoCreate: true);
        $count = 0;
        $docs = $this->documents($ctx->em->getConnection(), $folioCode, $coreCode, $dtoType, $limit, $count);
        $taskUid = $this->uploader->uploadDocuments($index, $docs, $primaryKey);

        if ($wait && $taskUid !== null) {
            $task = $this->meili->waitForTask((int) $taskUid);
            if (($task['status'] ?? null) !== 'succeeded') {
                throw new \RuntimeException(sprintf('Meilisearch task failed for %s: %s', $indexUid, json_encode($task['error'] ?? $task)));
            }
        }

        return ['indexUid' => $indexUid, 'count' => $count, 'taskUid' => $taskUid];
    }

    public function baseName(string $folioCode, string $coreCode = 'obj'): string
    {
        $base = $this->indexNameResolver->baseFromDataset($folioCode);
        return $coreCode === 'obj' ? $base : $base . '_' . $coreCode;
    }

    private function documents(
        \Doctrine\DBAL\Connection $connection,
        string $folioCode,
        string $coreCode,
        ?string $dtoType,
        ?int $limit,
        int &$count,
    ): \Generator {
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
        $sql .= ' ORDER BY i.id';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
            $params['limit'] = $limit;
        }

        $result = $connection->executeQuery($sql, $params);
        while (($row = $result->fetchAssociative()) !== false) {
            $count++;
            yield $this->documentBuilder->build(
                $folioCode,
                $coreCode,
                (string) $row['id'],
                (string) $row['local_id'],
                $row['label'] !== null ? (string) $row['label'] : null,
                $row['dto_type'] !== null ? (string) $row['dto_type'] : null,
                $this->decodeJson($row['dto_data'] ?? null),
                $this->decodeJson($row['extras'] ?? null),
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function decodeJson(mixed $value): ?array
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
