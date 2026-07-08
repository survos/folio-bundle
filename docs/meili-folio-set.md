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

> Why not `meili:populate` / `IndexProducer`? That path is Doctrine-entity only —
> it throws for non-entity classes and redirects file-backed collections to
> `meili:flush-file`. Folio rows come from per-folio SQLite via raw SQL, so the
> streaming `uploadDocuments()` primitive is the correct, lighter reuse.

The command is generic; the app decides *which* folios form a set. In the `zm` app
that mapping lives in `config/packages/folio_set.yaml` and is driven by
`app:meili:folio-set <code>`.
