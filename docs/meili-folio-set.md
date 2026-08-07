# `folio:meili:build-set` — pool several folios into one Meili index

Each folio is its own SQLite database and is normally indexed into its **own**
Meilisearch index (`folio:meili:populate`). `folio:meili:build-set` instead pools
the **common fields** of several folios into **one combined index**, so they can be
searched together (e.g. a "Fortepan" search over `mus/fortepan`, `mus/fpus`, and the
`fpeu/*` country folios).

```
bin/console folio:meili:build-set <indexBase> <folioCode...> [options]
```

| Argument / option | Default | Purpose |
|---|---|---|
| `indexBase` | — | Base index name; the Meili uid becomes `<MEILI_PREFIX><indexBase>` (e.g. `fs_fortepan` → `zmfs_fortepan`). |
| `folioCode...` | — | One or more folio codes (`provider/dataset`) to pool. |
| `--core` | `obj` | Core to read inside each folio. |
| `--fields` | `title,description,caption,denseSummary,subjects,tags,country,city,year` | Comma-separated content fields to keep. |
| `--reset` | off | Purge the index before indexing. |
| `--wait` | off | Wait for the Meili task and fail loudly on error. |
| `--pk` | `id` | Primary-key field. |
| `--keys` | off | Create/sync the managed search key (`MeiliServerKeyService::ensureServerKeys`) so a browser/InstantSearch client can query without a 401. Requires the master key. |
| `--locale` | none | Target index LOCALE (e.g. `en`) — suffixes the index uid via `IndexNameResolver::uidFor()` (`<base>_<locale>`) AND (since 2026-08-06) sets Meilisearch's `localizedAttributes` stemming/tokenization hint to that locale. Omit to write the raw/unsuffixed index with no locale hint asserted from CLI args (see "Locale hints" below). |
| `--open-locale` | none | Which per-folio file variant to open — independent of `--locale` (which only names the index/sets the hint). Omit to open each folio's default/source file; set to open that locale's translated `.{locale}.folio` build. `--locale=en --open-locale=en` is how you fill an `_en` index with genuinely-translated content, not just a mislabeled copy of the source. |
| `--all-fields` | off | Skip the `--fields` whitelist, index every `dtoData` key (still normalises `ai:`-prefixed aliases). |
| `--content-types` | none | Comma-separated `dtoData.contentType` allowlist — scans every core in the folio instead of just `--core`, filtered by this field. |

## How it works

- Streams rows from each folio's SQLite straight into
  `MeiliNdjsonUploader::uploadDocuments()` (chunked NDJSON, ~10 MB) — **no
  intermediate `.jsonl` file, no new upload code**. This mirrors
  `FolioMeiliIndexer` (single folio), generalised to N folios plus a projection.
- Each document is **projected** to the common schema: identity/routing keys
  (`id`, `folioCode`, `provider`, `dataset`, `coreCode`, `localId`, `dtoType`,
  `label`, `rp`) are always kept; the whitelisted content fields are kept when
  present; everything else is dropped so folios with divergent schemas still merge.
- Field **normalisation**: `ai:caption`→`caption`, `ai:denseSummary`→`denseSummary`
  (falling back to the plain keys). `tags` are lifted from `extras.source_tags`.
  Media/link keys (`thumbnailUrl`, `largeImageUrl`, `iiifBase`, `sourceUrl`,
  `citationUrl`) are carried verbatim for the hit template.
- The composite row id (`provider/dataset:core:localId`) contains `/` and `:`,
  which are **illegal in a Meili document id**, so it is sanitised to `[A-Za-z0-9_-]`
  for `id` and the original is preserved as `rowId`.
- Index **settings** are applied so an InstantSearch UI can auto-build widgets:
  `searchableAttributes` (title/description/caption/denseSummary/subjects/tags/country/city/label),
  `filterableAttributes` (provider/dataset/folioCode/coreCode + year/subjects/tags/country/city),
  `sortableAttributes` (`year`, numeric → range slider).

### Locale hints (since 2026-08-06)

Before this, **no** folio-built index ever got a Meilisearch `localizedAttributes`
hint — stemming/tokenization was unhinted for every one, including working
multi-locale demos (two genuinely different-language indexes, but neither told
Meili what language it held). Now:

- `--locale` explicit → always sets `localizedAttributes` to that locale
  (`[['locales' => [$locale], 'attributePatterns' => ['*']]]`) — the caller is
  asserting the resulting index's language, no further lookup needed.
- `--locale` omitted (the raw/pooled index, which can genuinely mix languages —
  e.g. a "Fortepan" pool spanning Hungarian/English/French folios) → best-effort
  via `Survos\DatasetBundle\Entity\DatasetInfo::$locale` per pooled folio code,
  **only** if every folio's `DatasetInfo` row exists and agrees on one locale.
  Any missing row or disagreement across the pool → no hint set, same as before
  (never asserts a guessed/wrong locale). Requires the app to have `dataset-bundle`
  installed; degrades to "no hint" cleanly if not (see the command's nullable
  `$datasets` constructor param).

> Why not `meili:populate` / `IndexProducer`? That path is Doctrine-entity only —
> it throws for non-entity classes and redirects file-backed collections to
> `meili:flush-file`. Folio rows come from per-folio SQLite via raw SQL, so the
> streaming `uploadDocuments()` primitive is the correct, lighter reuse.

The command is generic; the app decides *which* folios form a set. Both `zm` and
`openfoto` store that mapping as an `App\Entity\FolioSet` row (code/label/
indexBase/core/fields/folios) and drive this command through their own thin
`folioset:build <code>` wrapper (`folioset:save <code> --folio=... --folio=...` to
define the set first).
