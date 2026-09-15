<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DatasetBundle\Repository\DatasetInfoRepository;
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
 * Rows stream straight from each folio's SQLite ({@see FolioDocumentStream}) into
 * {@see MeiliNdjsonUploader::uploadDocuments()} (chunked NDJSON POSTs) — no
 * intermediate file is written.
 */
#[AsCommand('folio:meili:build-set', 'Index common fields from several folios into one combined index.')]
final class FolioMeiliBuildSetCommand
{
    public function __construct(
        private readonly FolioDocumentStream $stream,
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
        #[Option('Comma-separated extras keys to lift onto each document and make filterable, e.g. issueId,articleTier,recordKind. A pooled index otherwise carries dtoData only, and anything a DTO does not declare — which for newspaper articles is the issue they belong to and whether they are a stitched article or a single block — falls into extras and is dropped, leaving hits that cannot be linked to or faceted.')] ?string $extras = null,
    ): int {
        if ($folioCodes === []) {
            $io->error('Provide at least one folio code.');
            return Command::INVALID;
        }

        $keep = array_values(array_filter(array_map('trim', explode(',', $fields)), static fn (string $f): bool => $f !== ''));
        $extraKeys = $extras !== null
            ? array_values(array_filter(array_map('trim', explode(',', $extras)), static fn (string $k): bool => $k !== ''))
            : [];
        // Lifted extras are content, so they survive the --fields whitelist as well as --all-fields.
        $keep = array_values(array_unique(array_merge($keep, $extraKeys)));

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
            'filterableAttributes' => array_values(array_unique(
                array_merge(['provider', 'dataset', 'folioCode', 'coreCode'], $facetable, $extraKeys),
            )),
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

        $report = new FolioDocumentStreamReport();
        $taskUid = $this->uploader->uploadDocuments(
            $index,
            $this->stream->documents($folioCodes, $core, $allFields ? null : $keep, $openLocale, $contentTypeList, $extraKeys, $report),
            $pk,
        );
        $count = $report->count;
        $perFolio = $report->perFolio;
        $failed = $report->failed;

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
}
