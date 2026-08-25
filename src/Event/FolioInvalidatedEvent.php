<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by `folio:validate --fix` for each folio it found to be unusable.
 *
 * The decoupling seam for "this folio needs to be rebuilt". folio-bundle can SEE that a folio is
 * broken — it opens every one — but it must not decide what that means for the pipeline. Which
 * stage a dataset has to restart from, and which field records that, are app-owned: DatasetInfo's
 * own docblock says "the workflow definition is app-owned; keep the bundle entity decoupled from
 * app workflow constants", and as of 2026-08-25 harvest has `framework.workflows: null` with
 * `DatasetInfo.marking` still at its 'new' default on 4462 of 4474 rows — the field actually
 * carrying pipeline state is `status`. Hardcoding any of that here would bake one app's
 * half-migrated convention into a shared bundle.
 *
 * So: the bundle reports a fact ("euro/1207's folio has no rows"), the app decides the
 * consequence (reset status, queue a re-normalize, dispatch to harvest over JSON-RPC, or ignore
 * it). An app with no listener gets a validation report and nothing else, which is a fine default.
 *
 * Only dispatched under `--fix`. A dry run must stay a dry run: without that guard, a plain
 * `folio:validate` would silently reset pipeline state on every broken dataset it walked past.
 */
final class FolioInvalidatedEvent extends Event
{
    public function __construct(
        /** Dataset key WITHOUT any locale suffix, e.g. "euro/1207" — what the registry is keyed on. */
        public readonly string $datasetKey,
        /**
         * Why it is unusable, as one of {@see \Survos\FolioBundle\Command\FolioValidateCommand}'s
         * statuses: 'empty', 'not-sqlite', 'orphan', 'no-fts', 'empty-build', 'error'. These differ
         * in what they demand — 'no-fts' needs only `folio:fts:rebuild` on an otherwise healthy
         * folio, while 'empty-build' means ingest produced nothing and the dataset has to go back
         * to an earlier stage — so a listener should branch on this rather than treat all
         * invalidations alike.
         */
        public readonly string $reason,
        /** Human-readable specifics, e.g. "0 rows — rebuild from JSONL". */
        public readonly string $detail,
        /** Absolute path of the folio file, whether or not it still exists. */
        public readonly string $path,
        /** Locale for a translated build (`<code>.<locale>.folio`), null for the source-language one. */
        public readonly ?string $locale = null,
        /** True when the file was actually deleted; false when it was left in place for a human. */
        public readonly bool $deleted = false,
    ) {
    }
}
