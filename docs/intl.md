# Multilingual Folios

A folio stores normalized rows whose translatable text lives inside the `dto_data` JSON column. This document describes how those rows are served in a non-source language, where translations are produced, and how they enter the folio.

## The problem

Folio rows are JSON blobs, not entity properties. Babel's `#[Translatable]` + `titleBacking`/`title` property-hook pattern cannot apply — there is nothing to hook. But babel's *storage shape* (one row per source string, one row per (source, target) pair, identified by an xxh3 hash of `(sourceLocale + text)`) is the right shape. Folio reuses the shape, not the attachment mechanism.

## Data flow

```
import:convert --dataset=mus/larco --stage=normalize    [in harvest]
  └→ 20_normalize/obj.jsonl                  (rows)
  └→ 25_intl/strings.jsonl                   (extracted phrases, written by event listener)

dataset:intl:extract mus/larco               (idempotent rebuild from 20_normalize + 30_terms)
dataset:intl:push    mus/larco               (POST phrases to lingua.survos.com)
dataset:intl:pull    mus/larco               (GET translations → 25_intl/tr.<locale>.jsonl)
dataset:intl:status  mus/larco               (coverage report per target locale)

folio:ingest  mus/larco                      (schema update creates phrase + tr if absent;
                                              FolioIntlImporter loads 25_intl/*.jsonl)

# Runtime
GET /folios/mus/larco/obj/rows?locale=en
  └→ FolioPostLoadListener resolves dtoData via tr table  → resolvedDtoData
```

Direction of every dependency:

- `data-contracts` is depended on by everything; depends on nothing.
- `lingua-bundle` exposes `LinguaClient`. Knows about the lingua HTTP API. Does not know about datasets.
- `dataset-bundle` depends on `lingua-bundle` for the client. Owns `DatasetIntlService` and the convert-time extractor.
- `folio-bundle` depends on `dataset-bundle` for `DataPaths`. Owns the folio-side entities, importer, and postLoad listener.
- `import-bundle` is unchanged — `dataset-bundle` listens on its existing events.

## Where translatable strings are declared

On the DTO classes in `survos/data-contracts`:

```php
abstract class BaseItemDto {
    #[Translatable] public ?string $title = null;
    #[Translatable] public ?string $description = null;
    #[Translatable] public ?string $physicalDescription = null;
    #[Translatable] public ?string $contextDescription = null;
}
```

The `Translatable` attribute moves from babel-bundle to data-contracts; babel-bundle retains a `class_alias` for backward compatibility. The attribute is pure metadata — both babel and folio read it but apply different storage strategies.

`TranslatableReflector::fieldsFor($dtoClass)` is the canonical lookup: a public static helper in data-contracts that returns the list of translatable property names for a DTO class, cached for the process lifetime.

## Pipeline artifacts (25_intl/)

The `25_intl/` stage directory under each dataset holds the translation work-in-progress. It is a sibling of `20_normalize/` and `30_terms/`, not a successor.

```
data/<provider>/<dataset>/
  20_normalize/obj.jsonl
  25_intl/
    strings.jsonl            # { code, locale, text, sources[] } — one row per unique source phrase
    tr.en.jsonl              # { code, locale, text, engine }    — one row per known translation
    tr.es.jsonl
    tr.de.jsonl
  30_terms/
```

`strings.jsonl` is the union of:
- Translatable property values from normalized rows (one extractor pass over `20_normalize/*.jsonl`).
- Term labels from `30_terms/*.jsonl` (so 'oil' and 'canvas' get translated once per source language and shared across all rows that reference those terms).

Deduplication is by xxh3 hash. The `sources[]` list records which fields / term sets contributed the same phrase — useful for debugging, otherwise ignored.

`tr.<locale>.jsonl` files are produced by `dataset:intl:pull`. Each row is a confirmed translation; missing translations are simply absent. There is no "stub" or "queued" row in the JSONL layer — workflow state is the lingua server's job.

## Folio entities

Two new entities, added to `FolioSchemaManager::ENTITIES`:

| Table | Columns | Notes |
|-------|---------|-------|
| `phrase` | `code` (PK, xxh3), `source_locale`, `text`, `context` | One row per source phrase. |
| `tr` | `id`, `phrase_code`, `locale`, `text`, `engine` | One row per known translation. UNIQUE on `(phrase_code, locale)`, covering INDEX on `(locale, phrase_code)`. |

Schema creation happens during `folio:ingest`, not as a separate migrate step. `FolioService::context()` already calls `FolioSchemaManager::update()` when `ensureSchema: true`, and `SchemaTool::updateSchema()` is idempotent — adding `phrase` and `tr` to the entity list is sufficient to have them created on first ingest into any existing folio.

Names are deliberately short — folio storage code references these tables often.

Folio holds **known** translations only. There is no `status` column, no stub row, no workflow. If a translation is in the table, it is the translation. Missing translation = fall back to source text. Workflow lives in the pipeline, not in the read-side store.

`Row` gains a runtime cache property:

```php
private ?array $resolvedDtoData = null;
public function setResolvedDtoData(array $data): void { $this->resolvedDtoData = $data; }
public function getDtoData(): ?array { return $this->resolvedDtoData ?? $this->dtoData; }
```

Templates and serializers read through `getDtoData()`. The persisted `$dtoData` is never mutated.

## Runtime: postLoad lookup

One indexed query per loaded `Row`. The `(locale, phrase_code)` covering index makes this cheap; no JOIN, no entity hydration on the translation side.

```php
// FolioPostLoadListener::postLoad — abbreviated
$fieldToCode = [];
foreach ($index->fieldsFor($row->dtoType) as $field) {
    $value = $row->dtoData[$field] ?? null;
    if (is_string($value) && $value !== '') {
        $fieldToCode[$field] = HashUtil::calcSourceKey($value, $sourceLocale);
    }
}
$rows = $conn->executeQuery(
    'SELECT phrase_code, text FROM tr WHERE locale = ? AND phrase_code IN (?)',
    [$displayLocale, array_values($fieldToCode)],
    [\PDO::PARAM_STR, ArrayParameterType::STRING],
)->fetchAllKeyValue();

$resolved = $row->dtoData;
foreach ($fieldToCode as $field => $code) {
    if (isset($rows[$code])) $resolved[$field] = $rows[$code];
}
$row->setResolvedDtoData($resolved);
```

For batch reads (api-platform collections, browse command), measure before optimizing. Per-row queries against an indexed sqlite table are typically faster than the application-level batching alternatives.

## Locking and concurrency

`FolioConnectionWrapper::selectDatabase()` reopens the PDO connection on every folio switch. The defaults are wrong for folio's read-heavy + occasional-write workload:

- `busy_timeout = 0` — first write that contends with a reader dies with `SQLITE_BUSY`.
- `journal_mode = DELETE` — readers block writers; writers block readers.

The fix is applied once in the wrapper, right after the parent constructor in `selectDatabase()`:

```php
$this->executeStatement('PRAGMA journal_mode = WAL');     // readers don't block writers
$this->executeStatement('PRAGMA busy_timeout = 30000');   // 30s retry window on lock contention
$this->executeStatement('PRAGMA synchronous = NORMAL');   // safe with WAL, much faster
$this->executeStatement('PRAGMA foreign_keys = ON');
```

This is a folio-wide fix, not specific to translation. It also resolves the "background workflow writes die on first attempt" symptom seen elsewhere.

On top of WAL + busy_timeout, all intl writes use precise upserts inside a single transaction per file:

```sql
INSERT INTO phrase (code, source_locale, text, context)
VALUES (?, ?, ?, ?)
ON CONFLICT(code) DO NOTHING;

INSERT INTO tr (phrase_code, locale, text, engine)
VALUES (?, ?, ?, ?)
ON CONFLICT(phrase_code, locale) DO UPDATE
  SET text = excluded.text, engine = excluded.engine;
```

One prepared statement reused per row, one transaction per JSONL file. Bulk import of `25_intl/` into a folio is a single lock acquisition, not one per row.

## Command surface

In `survos/dataset-bundle`, exposed as method-level `#[AsCommand]` on `DatasetIntlService`:

| Command | Reads | Writes | Network |
|---------|-------|--------|---------|
| `dataset:intl:extract <dataset>` | `20_normalize/*.jsonl`, `30_terms/*.jsonl` | `25_intl/strings.jsonl` | No |
| `dataset:intl:push <dataset>` | `25_intl/strings.jsonl` | — | Yes (lingua) |
| `dataset:intl:pull <dataset>` | `25_intl/strings.jsonl` | `25_intl/tr.<locale>.jsonl` | Yes (lingua) |
| `dataset:intl:status <dataset>` | `25_intl/*` | — | No |

`folio:ingest` calls `FolioIntlImporter` after row ingest, populating `phrase` + `tr` from `25_intl/`. No `folio:intl:*` command surface — if folio translations go stale, re-ingest.

`lingua:push` / `lingua:pull` in lingua-bundle remain focused on the babel-table flow (apps that translate their own Doctrine entities via babel). Folio never touches them.

## App-level translation cache

The lingua server already holds approximately one million translated phrases across prior runs. Once this pipeline is implemented, datasets that overlap with existing translations (notably `mus/larco` for Spanish source and `mus/smk` for the SMK collection) will be searchable in English on first `dataset:intl:pull`, with no further server work.

To avoid re-requesting hashes that have already been pulled for a previous dataset, `DataPaths::translationLangDir($locale)` (already stubbed) holds an app-level cache under `APP_DATA_DIR/translation/<locale>/`. In harvest — the app that runs normalization for the `mus/*` provider — this cache lives alongside the dataset pipeline tree at `/mnt/x10/translation/`. The `dataset:intl:pull` command consults the cache before calling the server, and writes server responses back into it. A second dataset's pull is then a local filesystem walk for cached hashes plus a smaller server request for the uncached remainder.

## Implementation order

Each step is independently shippable.

1. **PRAGMA fix in `FolioConnectionWrapper`.** Four lines. Unblocks the background-job lock crash regardless of whether intl ships. Land first.
2. **Move `Translatable` to data-contracts, add `TranslatableReflector`.** Babel-bundle adds `class_alias` for compat. Pure metadata move.
3. **`TranslatableExtractor` + convert-time event listener in dataset-bundle.** End-to-end testable by running `import:convert --dataset=mus/cleveland --stage=normalize` and inspecting `25_intl/strings.jsonl`. No folio writes, no network.
4. **`phrase` + `tr` entities, `FolioIntlImporter`, `folio:ingest` integration.** Pre-translation, the importer writes only `phrase` rows. Folio behavior for source-language readers is unchanged.
5. **`DatasetIntlService::push` / `pull` / `status`.** Network-touching. Defer until 1–4 are confirmed in the harvest deployment.
6. **`FolioPostLoadListener` + `Row::$resolvedDtoData`.** Display-time translation. Bring online once real `tr` rows exist in a folio.
