# Building folios

Folio consumes explicit JSONL files and metadata. It does not discover datasets,
select pipeline stages, query a dataset registry, or advance a workflow.

Inject `FolioBuilder` and call `build(FolioBuildInput $input, bool $archive = false)`.
The input specifies the folio code, row-core files, metadata and source language.
Optional inputs include a target locale, page/term/link/claim files, translation
files keyed by language, and a reviewed translation override file. Supplied files
must exist and be readable. Omit optional streams when they are absent.

```php
use Survos\FolioBundle\Dto\FolioBuildInput;

$result = $builder->build(new FolioBuildInput(
    code: 'example/collection',
    sources: ['obj' => '/data/objects.jsonl'],
    metadata: ['label' => 'Example collection'],
    sourceLocale: 'en',
), archive: true);
```

The builder ingests into a temporary folio, saves a bare snapshot, optionally
compresses an archive, adds indexes/FTS/views, finalizes SQLite, and atomically
installs the working file. Empty or failed builds throw; they do not replace the
live working folio. The result contains working/bare/archive paths, row counts,
metadata and the built locale. The caller owns publication and registry updates.

Harvest's `DatasetFolioInputFactory` selects pipeline inputs, including the existing
freshness and materialized-assembly checks. `DatasetFolioBuilder` runs declared
locales and registers artifacts before announcing `FolioPublishedEvent`. The
DatasetInfo workflow, previews and completed English translation builds use it.
The shared bundle has no dependency on Harvest or DatasetBundle.

## Supported entry points

- Harvest: acquire durable raw, then `dataset:register provider/code`; use
  `--rebuild` to queue normalization through the workflow while retaining the
  published folio. This is asynchronous, not an inline build recipe.
- Readers: `folio:pull`, `folio:info`, `folio:browse`, and existing file/search tools.
- Metadata migration: `folio:migrate --file=/path/to/existing.folio`.
- Validation: `folio:validate` checks files; registry lookup is removed. Orphan
  detection uses a configured dataset API catalog when available, never guesses
  from an absent registry.
- Meili indexing: `folio:meili:build-set`; pass `--locale` when a language hint is
  known. No language is inferred from a dataset database.

Removed, without replacement commands: `folio:build`, `folio:archive`,
`folio:translate`, `folio:ingest`, and registry-wide `folio:migrate` selection.
Harvest also removes `dataset:scan`, `dataset:folio`, and stage commands' `--folio`
bypass. Archive and ingest engines remain services. Existing folio archives can
still be restored; rebuilding uses the owning application's workflow.

Sets and sitemaps use the published catalog. Sitemap entries additionally require
a local folio file. The dataset-registry collection controller/table is removed;
applications own their catalog UI. `folio_sets_source: registry` is no longer
supported; configure a catalog instead.

Producer configuration moves with the caller: Harvest uses `FOLIO_BUILD_ARCHIVE`
for its adapter and `app.reviewed_translations_dir` for input selection. The old
`survos_folio.build_archive` and `survos_folio.reviewed_translations_dir` options
are removed. Other producers pass archive preference and reviewed files directly.
