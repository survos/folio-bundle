<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Service\FolioMeiliDocumentBuilder;
use Survos\FolioBundle\Service\FolioService;
use Survos\MeiliBundle\Service\IndexNameResolver;
use Survos\MeiliBundle\Service\MeiliNdjsonUploader;
use Survos\MeiliBundle\Service\MeiliServerKeyService;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pool several folios into ONE combined Meilisearch index, keeping only a small
 * set of common fields. Generalises {@see \Survos\FolioBundle\Service\FolioMeiliIndexer}
 * (single folio) to N folios plus a field-projection step.
 *
 * Rows stream straight from each folio's SQLite into
 * {@see MeiliNdjsonUploader::uploadDocuments()} (chunked NDJSON POSTs) — no
 * intermediate file is written.
 */
#[AsCommand('folio:meili:build-set', 'Index common fields from several folios into one combined index.')]
final class FolioMeiliBuildSetCommand
{
    /** Identity/routing keys always carried over from the built document. */
    private const IDENTITY = ['id', 'folioCode', 'provider', 'dataset', 'coreCode', 'localId', 'dtoType', 'label', 'rp'];

    /** Media/thumbnail + outbound-link keys carried over verbatim when present (used by the hit template). */
    private const MEDIA = ['thumbnailUrl', 'largeImageUrl', 'iiifBase', 'sourceUrl', 'citationUrl'];

    /** Whitelisted field -> candidate source keys (first non-empty wins). Normalises ai:-prefixed keys. */
    private const SOURCES = [
        'caption' => ['ai:caption', 'caption'],
        'denseSummary' => ['ai:denseSummary', 'denseSummary', 'searchSummary'],
    ];

    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioMeiliDocumentBuilder $documentBuilder,
        private readonly MeiliService $meili,
        private readonly MeiliNdjsonUploader $uploader,
        private readonly IndexNameResolver $indexNameResolver,
        private readonly MeiliServerKeyService $serverKeyService,
    ) {
    }

    /**
     * @param list<string> $folioCodes
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Base index name, e.g. fs_fortepan (prefix is applied automatically)')] string $indexBase,
        #[Argument('Folio codes to pool, e.g. mus/fortepan mus/fpus fpeu/hungary')] array $folioCodes,
        #[Option('Core code inside each folio')] string $core = 'obj',
        #[Option('Comma-separated content fields to keep')] string $fields = 'title,description,caption,denseSummary,subjects,tags,country,city,year',
        #[Option('Reset index before indexing')] bool $reset = false,
        #[Option('Wait for Meilisearch task completion')] bool $wait = false,
        #[Option('Primary key field')] string $pk = 'id',
        #[Option('Create/sync the managed search key so the frontend can query (requires master key)')] bool $keys = false,
    ): int {
        if ($folioCodes === []) {
            $io->error('Provide at least one folio code.');
            return Command::INVALID;
        }

        $keep = array_values(array_filter(array_map('trim', explode(',', $fields)), static fn (string $f): bool => $f !== ''));

        $uid = $this->indexNameResolver->uidForRaw($indexBase);
        if ($reset) {
            $this->meili->purge($uid);
        }
        $index = $this->meili->getOrCreateIndex($uid, primaryKey: $pk, autoCreate: true);

        // Settings first so the InstantSearch UI can auto-build facets from filterableAttributes.
        $searchable = array_values(array_intersect(['title', 'description', 'caption', 'denseSummary', 'subjects', 'tags', 'country', 'city'], $keep));
        $searchable[] = 'label';
        $facetable = array_values(array_intersect(['year', 'subjects', 'tags', 'country', 'city'], $keep));
        $index->updateSettings([
            'searchableAttributes' => array_values(array_unique($searchable)),
            'filterableAttributes' => array_merge(['provider', 'dataset', 'folioCode', 'coreCode'], $facetable),
            'sortableAttributes' => array_values(array_intersect(['year'], $keep)),
        ]);

        $count = 0;
        $perFolio = [];
        $taskUid = $this->uploader->uploadDocuments(
            $index,
            $this->documents($folioCodes, $core, $keep, $count, $perFolio),
            $pk,
        );

        if ($wait && $taskUid !== null) {
            $task = $this->meili->waitForTask((int) $taskUid);
            if (($task['status'] ?? null) !== 'succeeded') {
                $io->error(sprintf('Meilisearch task failed for %s: %s', $uid, json_encode($task['error'] ?? $task)));
                return Command::FAILURE;
            }
        }

        if ($keys) {
            $this->serverKeyService->ensureServerKeys([$uid]);
            $io->writeln(sprintf('  managed search key ensured for %s', $uid));
        }

        foreach ($perFolio as $folioCode => $n) {
            $io->writeln(sprintf('  %s: %d row(s)', $folioCode, $n));
        }
        $io->success(sprintf(
            'Indexed %d row(s) from %d folio(s) into %s%s',
            $count,
            count($folioCodes),
            $uid,
            $taskUid !== null ? sprintf(' (task %d)', $taskUid) : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<string>          $folioCodes
     * @param list<string>          $keep
     * @param array<string,int>     $perFolio  filled with per-folio row counts
     * @return \Generator<array<string,mixed>>
     */
    private function documents(array $folioCodes, string $core, array $keep, int &$count, array &$perFolio): \Generator
    {
        $sql = <<<'SQL'
SELECT i.id, i.local_id, i.label, i.dto_type, i.dto_data, i.extras
FROM item i
JOIN core c ON c.id = i.core_id
WHERE c.code = :core
ORDER BY i.id
SQL;

        foreach ($folioCodes as $folioCode) {
            $perFolio[$folioCode] = 0;
            $connection = $this->folios->context($folioCode)->em->getConnection();
            $result = $connection->executeQuery($sql, ['core' => $core]);

            while (($row = $result->fetchAssociative()) !== false) {
                $doc = $this->documentBuilder->build(
                    $folioCode,
                    $core,
                    (string) $row['id'],
                    (string) $row['local_id'],
                    $row['label'] !== null ? (string) $row['label'] : null,
                    $row['dto_type'] !== null ? (string) $row['dto_type'] : null,
                    $this->decodeJson($row['dto_data'] ?? null),
                    null, // common-field index: drop source-specific extras (we lift only source_tags below)
                );

                // The raw fortepan tags live in extras.source_tags; expose them as `tags`.
                $extras = $this->decodeJson($row['extras'] ?? null);
                if (is_array($extras) && ($extras['source_tags'] ?? null)) {
                    $doc['tags'] = $extras['source_tags'];
                }

                $count++;
                $perFolio[$folioCode]++;
                yield $this->project($doc, $keep);
            }
        }
    }

    /**
     * @param array<string,mixed> $doc
     * @param list<string>        $keep
     * @return array<string,mixed>
     */
    private function project(array $doc, array $keep): array
    {
        $out = [];
        foreach (self::IDENTITY as $k) {
            if (array_key_exists($k, $doc)) {
                $out[$k] = $doc[$k];
            }
        }

        // The row id is a composite "folioCode:coreCode:localId" (e.g. "mus/fortepan:obj:1"),
        // whose "/" and ":" are illegal in a Meili document id. Keep the original as rowId and
        // use a sanitised, still-unique id as the primary key.
        if (isset($out['id'])) {
            $out['rowId'] = $out['id'];
            $out['id'] = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $out['id']);
        }

        // Thumbnail/image URLs for the hit template — kept verbatim when present.
        foreach (self::MEDIA as $k) {
            if (($doc[$k] ?? null) !== null && $doc[$k] !== '') {
                $out[$k] = $doc[$k];
            }
        }

        foreach ($keep as $field) {
            $candidates = self::SOURCES[$field] ?? [$field];
            foreach ($candidates as $src) {
                $value = $doc[$src] ?? null;
                if ($value !== null && $value !== '' && $value !== []) {
                    $out[$field] = $value;
                    break;
                }
            }
        }

        return $out;
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
