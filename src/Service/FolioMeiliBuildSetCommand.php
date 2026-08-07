<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
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
        // Nullable/optional -- same "erroring clearly at runtime, not a container compile
        // failure" pattern as FolioTranslateCommand's own $datasets param (SurvosFolioBundle.php)
        // for an app with folio-bundle but not dataset-bundle. Used only for the best-effort
        // raw-index locale hint below; the --locale-explicit case never touches it.
        private readonly ?DatasetInfoRepository $datasets = null,
    ) {
    }

    /**
     * Meilisearch `localizedAttributes` hint for THIS build -- a real language hint improves
     * stemming/tokenization relevance (confirmed nothing in this pipeline has ever set one:
     * fs_fortepan, fs_smith, even the working zmmus_enterreno/zmmus_enterreno_en bilingual demo
     * all have localizedAttributes unset today, checked live against the Meili server).
     *
     * $locale explicit (the caller is building a named-locale index, e.g. --locale=en) is
     * high-confidence -- the caller is asserting the resulting index's language, no lookup
     * needed. The raw/unsuffixed pool (no --locale) is the opposite case: it can genuinely mix
     * languages (fs_fortepan pools Hungarian + English + French folios), so nothing here may
     * assert a single locale from CLI args alone. DatasetInfo::$locale (dataset-bundle, already
     * a bundle-level -- not app-level -- concept, the same per-folio source-of-truth
     * FolioIngestService/FolioTranslateCommand/zm's own FolioLabelExtension already read this
     * exact way) is consulted as a best-effort signal instead, but ONLY returns a hint if every
     * pooled folio's DatasetInfo agrees on one non-empty locale -- any missing row or
     * disagreement means "don't know", never a guess.
     *
     * @param list<string> $folioCodes
     * @return list<array{locales: list<string>, attributePatterns: list<string>}>|null
     */
    private function resolveLocalizedAttributes(?string $locale, array $folioCodes): ?array
    {
        if ($locale !== null) {
            return [['locales' => [strtolower($locale)], 'attributePatterns' => ['*']]];
        }

        if ($this->datasets === null) {
            return null;
        }

        $agreed = null;
        foreach ($folioCodes as $folioCode) {
            $folioLocale = $this->datasets->find($folioCode)?->locale;
            if ($folioLocale === null || $folioLocale === '') {
                return null;
            }
            $folioLocale = strtolower($folioLocale);
            if ($agreed === null) {
                $agreed = $folioLocale;
            } elseif ($agreed !== $folioLocale) {
                return null;
            }
        }

        return $agreed !== null ? [['locales' => [$agreed], 'attributePatterns' => ['*']]] : null;
    }

    /**
     * @param list<string> $folioCodes
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Base index name, e.g. fs_fortepan (prefix is applied automatically)')] string $indexBase,
        #[Argument('Folio codes to pool, e.g. mus/fortepan mus/fpus fpeu/hungary')] array $folioCodes,
        #[Option('Core code inside each folio')] string $core = 'obj',
        #[Option('Comma-separated content fields to keep')] string $fields = 'title,description,caption,denseSummary,subjects,tags,country,city,year,donor',
        #[Option('Reset index before indexing')] bool $reset = false,
        #[Option('Wait for Meilisearch task completion')] bool $wait = false,
        #[Option('Primary key field')] string $pk = 'id',
        #[Option('Create/sync the managed search key so the frontend can query (requires master key)')] bool $keys = false,
        #[Option('Target index locale (e.g. en); resolves the index uid through IndexNameResolver instead of uidForRaw. Omit to write the raw/unsuffixed index.')] ?string $locale = null,
        #[Option('Skip the --fields whitelist and index every dtoData key (still normalises ai:-prefixed aliases)')] bool $allFields = false,
        #[Option('Which per-folio file variant to open — independent of --locale (which only names the index). Null opens each folio\'s default/source file; a value opens that locale\'s translated build. This is how one --locale index can be filled by two calls: natively-in-locale folios with --open-locale omitted, translated folios with --open-locale=<locale>.')] ?string $openLocale = null,
        #[Option('Comma-separated dtoData.contentType allowlist (e.g. object,photograph,drawing,painting,print,sculpture). When set, scans EVERY core in the folio instead of just --core and filters per-item by this field — a provider\'s core code is schema/table naming, not a reliable content signal (e.g. NARA files real objects AND scanned text-document pages under the same "doc" core; contentType tells them apart). Omit to keep the legacy single-core behavior.')] ?string $contentTypes = null,
    ): int {
        if ($folioCodes === []) {
            $io->error('Provide at least one folio code.');
            return Command::INVALID;
        }

        $keep = array_values(array_filter(array_map('trim', explode(',', $fields)), static fn (string $f): bool => $f !== ''));

        // isMultiLingual: true forces the "<base>_<locale>" uid unconditionally — this command's
        // caller always wants one physical index per locale, regardless of the app-wide
        // survos_meili.multiLingual toggle (which most apps here don't set).
        $uid = $locale !== null
            ? $this->indexNameResolver->uidFor($indexBase, $locale, isMultilingual: true)
            : $this->indexNameResolver->uidForRaw($indexBase);
        if ($reset) {
            $this->meili->purge($uid);
        }
        $index = $this->meili->getOrCreateIndex($uid, primaryKey: $pk, autoCreate: true);

        // Settings first so the InstantSearch UI can auto-build facets from filterableAttributes.
        $searchable = $allFields
            ? ['title', 'description', 'caption', 'denseSummary', 'subjects', 'tags', 'country', 'city']
            : array_values(array_intersect(['title', 'description', 'caption', 'denseSummary', 'subjects', 'tags', 'country', 'city'], $keep));
        $searchable[] = 'label';
        $facetable = $allFields
            ? ['year', 'subjects', 'tags', 'country', 'city', 'donor']
            : array_values(array_intersect(['year', 'subjects', 'tags', 'country', 'city', 'donor'], $keep));
        // localId is always sortable regardless of --fields: TenantController::meiliAdjacentUrls()
        // uses it as the tie-breaker after year for a stable total order (same year has runs of
        // 5+ items in some folios, which year alone can't disambiguate).
        $sortable = $allFields ? ['year'] : array_values(array_intersect(['year'], $keep));
        $sortable[] = 'localId';
        $settingsPayload = [
            'searchableAttributes' => array_values(array_unique($searchable)),
            'filterableAttributes' => array_merge(['provider', 'dataset', 'folioCode', 'coreCode'], $facetable),
            'sortableAttributes' => $sortable,
        ];
        $localizedAttributes = $this->resolveLocalizedAttributes($locale, $folioCodes);
        if ($localizedAttributes !== null) {
            $settingsPayload['localizedAttributes'] = $localizedAttributes;
        }
        $index->updateSettings($settingsPayload);

        $contentTypeList = $contentTypes !== null
            ? array_values(array_filter(array_map('trim', explode(',', $contentTypes)), static fn (string $t): bool => $t !== ''))
            : null;

        $count = 0;
        $perFolio = [];
        $failed = [];
        $taskUid = $this->uploader->uploadDocuments(
            $index,
            $this->documents($folioCodes, $core, $keep, $count, $perFolio, $failed, $openLocale, $allFields, $contentTypeList),
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

        if ($failed !== []) {
            $io->warning(sprintf('Skipped %d folio(s) that failed to open/read:', count($failed)));
            foreach ($failed as $folioCode => $message) {
                $io->writeln(sprintf('  %s: %s', $folioCode, $message));
            }
        }

        $io->success(sprintf(
            'Indexed %d row(s) from %d/%d folio(s) into %s%s',
            $count,
            count($folioCodes) - count($failed),
            count($folioCodes),
            $uid,
            $taskUid !== null ? sprintf(' (task %d)', $taskUid) : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<string>          $folioCodes
     * @param list<string>          $keep
     * @param array<string,int>     $perFolio      filled with per-folio row counts
     * @param array<string,string>  $failed        filled with folioCode => error message for folios that couldn't be read
     * @param list<string>|null     $contentTypes  when set, scans every core and filters by dtoData.contentType instead of a fixed --core
     * @return \Generator<array<string,mixed>>
     */
    private function documents(array $folioCodes, string $core, array $keep, int &$count, array &$perFolio, array &$failed, ?string $openLocale, bool $allFields, ?array $contentTypes): \Generator
    {
        // Core code is schema/table naming, not a content signal — the same folio can file real
        // objects and scanned text-document pages under the same core (see NARA). $contentTypes
        // filters on the item's actual dtoData.contentType across every core in the folio instead.
        $sql = $contentTypes !== null
            ? <<<'SQL'
SELECT i.id, i.local_id, i.label, i.dto_type, i.dto_data, i.extras, c.code AS core_code
FROM item i
JOIN core c ON c.id = i.core_id
WHERE json_extract(i.dto_data, '$.contentType') IN (:contentTypes)
ORDER BY i.id
SQL
            : <<<'SQL'
SELECT i.id, i.local_id, i.label, i.dto_type, i.dto_data, i.extras, c.code AS core_code
FROM item i
JOIN core c ON c.id = i.core_id
WHERE c.code = :core
ORDER BY i.id
SQL;

        // At the scale folio:meili:build-all runs this at (thousands of independently-built
        // folios), a single stale/moved/schema-drifted file is expected, not exceptional — one
        // bad folio must not abort the whole streamed upload. Skip and keep going.
        foreach ($folioCodes as $folioCode) {
            $perFolio[$folioCode] = 0;

            try {
                $connection = $this->folios->context($folioCode, locale: $openLocale)->em->getConnection();
                $result = $contentTypes !== null
                    ? $connection->executeQuery($sql, ['contentTypes' => $contentTypes], ['contentTypes' => ArrayParameterType::STRING])
                    : $connection->executeQuery($sql, ['core' => $core]);

                while (($row = $result->fetchAssociative()) !== false) {
                    $doc = $this->documentBuilder->build(
                        $folioCode,
                        (string) $row['core_code'],
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
                    yield $this->project($doc, $keep, $allFields);
                }
            } catch (\Throwable $e) {
                $failed[$folioCode] = $e->getMessage();
            }
        }
    }

    /**
     * @param array<string,mixed> $doc
     * @param list<string>        $keep
     * @return array<string,mixed>
     */
    private function project(array $doc, array $keep, bool $allFields): array
    {
        $doc = $this->normalizeAliases($doc);
        $out = $allFields ? $doc : $this->projectWhitelist($doc, $keep);

        // The row id is a composite "folioCode:coreCode:localId" (e.g. "mus/fortepan:obj:1"),
        // whose "/" and ":" are illegal in a Meili document id. Keep the original as rowId and
        // use a sanitised, still-unique id as the primary key.
        if (isset($out['id'])) {
            $out['rowId'] = $out['id'];
            $out['id'] = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $out['id']);
        }

        return $out;
    }

    /**
     * Lift ai:-prefixed (and other aliased) keys onto their canonical field name in place, so
     * both the whitelist and allFields paths see e.g. `caption` regardless of which source key
     * the folio actually populated.
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private function normalizeAliases(array $doc): array
    {
        foreach (self::SOURCES as $field => $candidates) {
            if (($doc[$field] ?? null) !== null && $doc[$field] !== '' && $doc[$field] !== []) {
                continue;
            }
            foreach ($candidates as $src) {
                $value = $doc[$src] ?? null;
                if ($value !== null && $value !== '' && $value !== []) {
                    $doc[$field] = $value;
                    break;
                }
            }
        }

        return $doc;
    }

    /**
     * @param array<string,mixed> $doc
     * @param list<string>        $keep
     * @return array<string,mixed>
     */
    private function projectWhitelist(array $doc, array $keep): array
    {
        $out = [];
        foreach (self::IDENTITY as $k) {
            if (array_key_exists($k, $doc)) {
                $out[$k] = $doc[$k];
            }
        }

        // Thumbnail/image URLs for the hit template — kept verbatim when present.
        foreach (self::MEDIA as $k) {
            if (($doc[$k] ?? null) !== null && $doc[$k] !== '') {
                $out[$k] = $doc[$k];
            }
        }

        foreach ($keep as $field) {
            if (($doc[$field] ?? null) !== null && $doc[$field] !== '' && $doc[$field] !== []) {
                $out[$field] = $doc[$field];
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
