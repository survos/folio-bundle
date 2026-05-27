# Session Notes — 2026-05-25/26

## What We Did

### Documentation & Architecture
- Wrote `docs/folio-states.md` — defines archive vs working states, transitions (inflate/deflate), naming conventions
- Confirmed SQLite preserves SQL comments in `sqlite_master.sql` — no need for custom `_table`/`_column` registry
- Added `options: ['comment' => '...']` to every `#[Column]` across all 10 folio entity classes — DDL is now self-documenting
- Added `_meta` and `_index` tables to the design doc (not yet implemented as entities)
- Created ER diagram via `jawira/doctrine-diagram-bundle`, embedded in schema page

### Schema Page (`/folio/{provider}/{dataset}/schema`)
- Vertical tabs: diagram + per-table DDL + views
- highlight.js for SQL syntax highlighting (CDN, folio-bundle only for now)
- Formatted DDL with one column per line
- Indexes shown as plain HTML table per table
- `_bootstrap` special route for viewing the empty schema
- Schema button added to folio show page
- Admin navbar menu (`FolioMenu`) with bootstrap schema link

### Entity Changes
- JSON columns (`dtoData`, `extras`, `rules`, `meta`) changed from `DEFAULT '{}'` to nullable — saves space, cleaner semantics
- `language` field in `BaseItemDto` changed from `?array` to `?string`
- Added `Claim` entity — FK to Row, stores predicate/value/source/confidence/agent/claimedAt/meta
- Row entity has `$claims` OneToMany collection
- Claim registered in `FolioSchemaManager::ENTITIES`

### camelCase Convention
- `ItemField` constant values changed from snake_case to camelCase (`'iiif_base'` → `'iiifBase'`, etc.)
- Convention documented in `~/sites/showcase/CONVENTIONS.md`
- Assert added in `BaseItemDto::fromNormalized()` — warns on snake_case keys during dev
- Cleveland normalizer updated to output camelCase
- DC normalizer (`DcSetRecordListener`) updated to use `ItemField::` constants + camelCase keys
- `FolioDtoTypeResolver` updated to check `contentType` (camelCase)
- Removed `dtoClass` from normalized output — derived at ingest time

### Namespace Rename: `Survos\DataBundle` → `Survos\DatasetBundle`
- All 44 PHP files in `bu/dataset-bundle/src/` renamed
- `composer.json` PSR-4 autoload updated
- Consumers updated: folio-bundle, import-bundle, data-bundle, ai-dataset-bundle, claims-bundle, harvest, zm, ssai, md
- `bundles.php` fixed in zm, harvest, md, ssai, folio-demo
- `installed.json` needed manual fix for symlinked packages (composer doesn't re-read symlinked composer.json on dump-autoload)
- Hit template renamed: `data_dataset_info.html.twig` → `dataset_dataset_info.html.twig`

### Archive & Publish Pipeline
- `FolioArchiveService::archive()` — now systematically drops ALL user indexes, FTS tables, vocab tables, and views before VACUUM + gzip
- `FolioArchiveService::inflate()` — recreates standard indexes, rebuilds views from schema_property, rebuilds FTS
- `folio:publish` command — deflates + uploads to HF via `hf upload`
- `folio:pull` command — downloads from HF via `hf download`, gunzips, inflates
- Created HF repo: `museado/folios` with README/dataset card
- Published `mus/cleveland` (1.2 MB → 68.8 KB, 94% compression)
- Tested full round-trip in `folio-demo`: pull from HF → inflate → dataset:scan → browse

### Import Pipeline
- `import:convert --provider=mus` option added — iterates datasets from Provider entity
- Entity resolver for console (`MapEntity`) not yet working — using manual repository lookup
- `EntityManagerInterface` added as optional constructor arg
- `SurvosUtils::removeNullsAndEmptyArrays` → `Arrays::sparse()` migration (deprecated in core-bundle)
- `VocabTermExtractorListener::extractLang()` coerces to string

### Bootstrap Folio
- DBAL connection path decoupled from `APP_DATA_DIR` → `%kernel.project_dir%/var/folio/_bootstrap.folio.sqlite`
- `FolioService::switch()` recognizes `_bootstrap/_bootstrap` as no-op (uses default connection)
- Bootstrap schema rebuilt with comments after entity changes

### Bundle Registration Guards
- `FolioMenu` only registered when `AbstractAdminMenuSubscriber` class exists (tabler-bundle optional)
- `FolioRowProvider` only registered when `ApiPlatform\State\ProviderInterface` exists
- Prevents class-not-found errors in lightweight apps like folio-demo

### Image Handling
- Cleveland normalizer: `iiifBase` set to `_web.jpg` (largest JPG available, ~650px)
- `Row::getThumbnailSource()` — appends `/full/max/0/default.jpg` for IIIF endpoints, passes static URLs through
- Detail page: image wrapped in `<a href target=_blank>`, imgproxy via `|imgproxy('medium')`
- Search hit template: uses `iiifBase` directly (no more IIIF path construction in template)

### Apps Configured
- **zm**: folio EM, bootstrap path, home page switched to artifact UX search
- **ssai**: folio EM + connection added, namespace refs fixed, `dataset:scan` works
- **harvest**: sqlite DB, `APP_DATA_DIR`, `ZIPS_DIR`, all normalizers working
- **md**: namespace refs fixed, `DcSetRecordListener` uses ItemField constants, `Arrays::sparse()`
- **folio-demo**: standalone app, pulls from HF, local data dir, no `APP_DATA_DIR` dependency

### zm Home Page
- Switched from dataset search to artifact search (`dataset_artifact`)
- Created `search/hits/dataset_artifact.html.twig` — shows folio collection cards with Browse/Search/Schema links
- Hero stats: Collections, Providers, Items counts
- Card grid: `col-12 col-sm-6 col-xl-4`

## Open Items (Priority Order)

### Before Publishing More Folios
1. **`BaseItemDto` docblocks → `#[PropertyMeta]` attributes** — schema snapshotter reads these for `schema_property.description` and view comments. Currently parsing docblocks as fallback.
2. **Publish all mus folios to HF** — `folio:publish --provider=mus`
3. **Normalize + ingest dc from md** — `import:convert --provider=dc` from md, then `folio:ingest --provider=dc` from zm
4. **Publish dc folios to HF**

### Architecture
5. **IIIF manifest fetch/cache** — during normalize, fetch manifests and cache resolved image URLs in `05_raw/iiif.jsonl`. See `memory/project_iiif_manifest_fetch.md`.
6. **Folio-bundle dependency cleanup** — move `dataset-bundle`, `field-bundle`, `ai-agent`, `stop-words` from require to suggest. Guard with `class_exists()`. See `memory/project_folio_deps_cleanup.md`.
7. **Claims ingestion** — `folio:ingest` should read `claims` array from JSONL rows and persist `Claim` entities. Important for ssai.
8. **`_meta` and `_index` entities** — implement the infrastructure tables from folio-states.md for proper inflate/deflate state tracking.

### Quality
9. **Console entity resolver** — `Symfony\Bridge\Doctrine\ArgumentResolver\Console\EntityValueResolver` exists but isn't tagged as `console.argument_value_resolver`. File as doctrine-bundle issue or register manually.
10. **dataset-bundle `ZIPS_DIR`** — default should be null or `APP_DATA_DIR/zips`, not a required env var.
11. **`dataset-bundle` namespace** — still `Survos\DataBundle` in the actual data-bundle (VocabLabel, VocabMap). These are different bundles sharing a namespace. data-bundle should eventually become `survos/vocab-bundle` or merge into dataset-bundle.

### Deferred
12. **Folio docs page** — wiki-like browser for the `docs` table (JSON/MD/HTML rendering). Needs highlight.js (already added to schema page).
13. **Word cloud** — `WordCloudService` with TF-IDF from fts5vocab. Designed in `docs/word-cloud.md`.
14. **Vector search** — hybrid FTS5 + sqlite-vec with RRF. Intentionally deferred.

## Key Files Changed

### folio-bundle
- `src/Entity/` — all 10 entities + new `Claim.php` (comments, nullable JSON, claims relation)
- `src/Controller/FolioController.php` — schema route, `formatDdl()`, `bootstrapConnection()`
- `src/Service/FolioArchiveService.php` — systematic deflation, `inflate()` method
- `src/Service/FolioViewBuilder.php` — SQL comments from schema_property descriptions
- `src/Service/FolioSchemaManager.php` — Claim added to ENTITIES
- `src/Service/FolioService.php` — `_bootstrap/_bootstrap` skip, `bootstrapConnection()`
- `src/Service/FolioDtoTypeResolver.php` — camelCase `contentType` lookup
- `src/Command/FolioPublishCommand.php` — new
- `src/Command/FolioPullCommand.php` — new
- `src/Command/FolioIngestCommand.php` — TypeError catch for DTO split
- `src/Menu/FolioMenu.php` — admin navbar, guarded registration
- `src/SurvosFolioBundle.php` — new commands registered, class_exists guards
- `templates/folio/schema.html.twig` — new
- `templates/folio/show.html.twig` — schema button
- `templates/folio/row.html.twig` — imgproxy image with link
- `templates/folio/core.html.twig` — thumbnailSource for images
- `templates/folio/_er-diagram.svg` — new
- `docs/folio-states.md` — new
- `docs/folio-er.svg`, `docs/folio-class.svg` — new

### data-contracts
- `src/Vocabulary/ItemField.php` — all values → camelCase
- `src/Dto/Item/BaseItemDto.php` — language ?string, assert for camelCase, fromNormalized fixes

### dataset-bundle
- All files: `Survos\DataBundle` → `Survos\DatasetBundle`
- `composer.json` — PSR-4 autoload updated
- `src/Command/ScanDatasetsCommand.php` — FolioService injection, reads folio label/count

### import-bundle
- `src/Command/ImportConvertCommand.php` — `--provider` option, EntityManager injection, namespace fix

### harvest
- `src/Dataset/Cleveland.php` — iiifBase = web.jpg, camelCase keys, removed dtoClass
- All datasets: namespace fix

### md
- `src/Enhance/DcSetRecordListener.php` — ItemField constants, camelCase keys, language scalar fix, Arrays::sparse()

### zm
- `config/packages/doctrine.yaml` — local bootstrap path
- `config/bundles.php` — both DataBundle + DatasetBundle
- `src/Controller/HomeController.php` — artifact-based folio listing
- `templates/home/index.html.twig` — artifact UX search
- `templates/search/hits/dataset_artifact.html.twig` — new
- `templates/search/hits/folio_row.html.twig` — iiifBase direct
- `templates/bundles/MezcalitoUxSearchBundle/Hits.html.twig` — column widths

### ssai
- `config/packages/doctrine.yaml` — folio DBAL + ORM added
- `config/bundles.php` — fixed namespace, added folio-bundle
- `src/` — all DataBundle → DatasetBundle namespace fixes

### showcase
- `CONVENTIONS.md` — camelCase data keys convention added
