# Folio Build Pipeline

How normalized data becomes a distributable folio archive and a queryable working
folio. This is the design spec for `folio:build` and the artifact layout. It builds
on the state model in [folio-states.md](folio-states.md) (archive vs working;
`inflate`/`deflate` as the transition verbs).

## The core idea

A folio is produced in **two steps**, with compression as an orthogonal concern:

```
normalized JSONL
   │  step 1: write rows (no indexes)        →  bare .folio   (transient scratch)
   │
   ├─ gzip the bare file                     →  ARCHIVE: <code>.folio.gz   (index-free, uploadable)
   │
   └─ step 2: inflate (indexes + FTS5 + views) →  WORKING: <code>.folio    (queryable, searchable)
```

- **Step 1** (`build`) turns normalized JSONL into a SQLite file containing just the
  rows — no secondary indexes, no FTS5, no views. It is a real, queryable database;
  it just scans instead of seeking, and has no full-text search yet.
- **Step 2** (`inflate`) adds the secondary indexes, the FTS5 virtual table
  (`item_fts` + `item_vocab`), and the `dto_*` views. This is what makes a folio fast
  *and* searchable — search is a capability step 2 adds, not merely an optimization.
- **gzip** is transport compression applied to the index-free step-1 file. It is not a
  state; see folio-states.md.

The bare, uncompressed, index-free `.folio` is **never a durable artifact**. In
practice you always want either the `.gz` (to ship) or the inflated working file (to
use). So the bare file is transient build scratch; `build` ends at one or both of the
two durable forms.

## `folio:build` (replaces `folio:ingest`)

`folio:ingest` is renamed to `folio:build`: the command's *product* is a folio
artifact (an archive, optionally also a working DB), not just an "ingest" side effect.

By default `folio:build` produces **both** durable forms:

| Output | Path | Suppress with |
|--------|------|---------------|
| Archive `.folio.gz` (index-free) | `folio-archive/<provider>/<code>.folio.gz` | `--no-archive` |
| Working `.folio` (inflated) | `folio/<provider>/<code>.folio` | `--no-inflate` |

- A **build/upload box** that only ships: `folio:build --provider dc --no-inflate`
  → produces only the `.gz`, never spends time building indexes/FTS it won't use.
- A **local dev** that only wants to search: `folio:build dc/05747667f --no-archive`
  → produces only the working folio.
- Default (both) is the right thing on a workstation that ships *and* browses.

Existing options carry over from `folio:ingest`: `dataset` argument, `--all`,
`--provider`, `--core`, `--id-field`, `--label-field`, `--batch`.

### Finalized option surface

```
folio:build [options] [--] [<dataset>]

  <dataset>            single dataset key (omit when using --all/--provider)
  --all                every dataset with a normalized source
  --provider=P         every dataset for one provider
  --archive            write the .gz archive   (default true)   → --no-archive to skip
  --inflate            build the working .folio (default true)  → --no-inflate to skip
  --allow-empty        build even if 20_normalize is empty/missing (default: skip + warn)
  --force              rebuild even if artifacts are newer than the source
  --dry-run            report would-build / would-overwrite / would-skip; no writes
  --core=CORE          default from DatasetInfo or "obj"
  --id-field=ID        default "id"
  --label-field=LABEL  default "label"
  --batch=N            flush batch size, default 500
```

The on/off outputs use nullable bools so "unset" is distinguishable, defaulting to true
in the body:

```php
#[Option('Write the .gz archive')]      ?bool $archive = null,   // → $archive ??= true;
#[Option('Build the working folio')]    ?bool $inflate = null,   // → $inflate ??= true;
```

Behavior:
- **Refuse** if both `--no-archive` and `--no-inflate` — nothing durable to produce.
- **Empty-source guard:** skip (with a warning) any dataset whose `20_normalize` is
  missing/empty rather than overwrite a good folio with an empty one. `--allow-empty`
  overrides. *(This is what stops `folio:build --provider mus` from wiping auur.)*
- **Freshness-aware:** skip a dataset whose requested artifacts are all newer than the
  newest `20_normalize/*.jsonl`. `--force` rebuilds regardless.
- **Derived paths only** — no `--output`/`--archive-path` override. Paths come from the
  layout below.
- **No `--publish`.** Build is purely local; uploading stays in `folio:publish`, which
  may target HF *or* S3 — a separate concern that needs fast internet.

### Where the saving comes from

The win over today's "ingest-with-indexes then publish-without" is that the archive is
snapshotted from the **row-only** database, *before* any indexing:

1. write rows → bare `.folio`
2. gzip the bare file → `.folio.gz`   ← never built indexes to throw away
3. if `--inflate` (default): add indexes/FTS/views to the working `.folio`

This avoids today's `FolioArchiveService::archive()` cost of copying a fully-indexed
multi-hundred-MB DB and VACUUMing it just to strip what was just built. Indexes are
built **once**, only when a working folio is actually wanted.

## Artifact homes

| State | Location | Lifetime |
|-------|----------|----------|
| Archive `.folio.gz` | `${APP_DATA_DIR}/folio-archive/<provider>/<code>.folio.gz` | durable — the thing you upload / re-upload |
| Working `.folio` | `${APP_DATA_DIR}/folio/<provider>/<code>.folio` | **cache** — rebuildable from the `.gz`, never uploaded |
| Bare index-free `.folio` | temp scratch during `build` | transient — gz'd and/or inflated, then deleted |

`folio-archive/` is a **distinct top-level dir**, deliberately separate from the
existing raw `archive/` tree (which holds raw `jsonl.gz`, per `zips_root: archive`).
Rule of thumb: **`archive/`** and **`folio-archive/`** = things you'd re-upload;
**`folio/`** = things you can delete and rebuild.

Canonical source of truth for an archive is the **remote** copy (HF, later S3); the
local `folio-archive/` copy is upload staging / download cache.

## Remote: Hugging Face (S3-ready)

- Start with a **single repo**: `museado/folios`, path-in-repo `<provider>/<code>.folio.gz`.
- Make the target a **provider → repo resolver**: a small config map that defaults
  every provider to `museado/folios` today. Going multi-repo later
  (e.g. `museado/smith-folios`) becomes a config change, not a code change.
- `folio:publish` keeps `--repo` as an explicit override of the resolver.
- Auth via `HUGGING_FACE_API_KEY`; upload via the `hf` CLI.
- **S3 is not built yet** — only the path layout is designed to accommodate it.

## Neighboring commands after this change

- **`folio:publish`** stops deflating. It uploads the existing `.gz` from
  `folio-archive/` (building it first if absent). No more copy+VACUUM on publish.
- **`folio:archive`** (working → `.gz`) becomes largely redundant. The only surviving
  case is re-archiving a folio that was **mutated after inflate** (e.g. AI enrichment
  writing claims into the working DB). Decide whether to keep it for that or drop it.
- **`folio:restore` / `folio:pull`** are unchanged in spirit — the HF-pull direction:
  download `.gz` → `folio-archive/` → inflate → `folio/`. `--inflate` on `build` covers
  the *local* build-and-use case; `restore` covers the *remote* fetch-and-use case.
- `inflate()` / `deflate()` remain the `FolioArchiveService` method names.

## Cross-project architecture

Normalization is driven by **app-specific event listeners**, so it must run in the app
that owns those listeners. Folio *build* is not app-specific (it just reads normalized
JSONL → SQLite), so it is centralized in zm.

| Project | Role | Normalizes | Folios |
|---------|------|-----------|--------|
| `harvest` | harvest + normalize | **mus** | none (kept folio-free on purpose) |
| `md` | harvest + normalize | **dc, fortepan, smith** (the rest) | debugging only |
| `zm` | build + serve + publish | — | canonical; the exposed ones |

Data flows on a shared disk: harvest/md write `…/<provider>/<dataset>/20_normalize/*.jsonl`;
zm reads those, builds folios, and publishes. zm's `survos_dataset.providers` therefore
lists everything it builds folios for (`dc, mus, fortepan, smith`).

End-to-end per provider:

```
[harvest | md]  normalize (app listeners)                 → 20_normalize/*.jsonl   (full: import:convert --limit 0)
[zm]            dataset:scan                               → register in DatasetInfo
[zm]            folio:build --provider <p> --gz            → folio-archive/<p>/*.folio.gz  (+ folio/<p>/*.folio)
[zm]            folio:publish --provider <p>               → hf upload  (fast internet)
[production]    folio:pull --provider <p> --force          → download .folio.gz, restore, inflate working folio
```

- **"Not limited"** = `import:convert --limit 0` upstream + `folio:build` (no row cap) = full data.
- **"No translations"** = build reads `20_normalize`, not the `25_intl/tr.<locale>.jsonl`
  stage produced by `dataset:intl:pull`. As long as intl is not merged in, folios carry
  no translations. (See [intl.md](intl.md).)

## Current operational workflow (2026-06)

`folio:build` builds the working `.folio` by default, but the distributable `.folio.gz` archive is opt-in. If production needs to pull from Hugging Face, build with `--gz` before publishing.

```bash
# Rebuild one folio and its uploadable archive
APP_DATA_DIR=/media/tac/x10a php bin/console folio:build --dataset fortepan/hu --gz --force --no-debug
php bin/console folio:publish --dataset fortepan/hu --repo museado/folios

# Rebuild and publish a provider
APP_DATA_DIR=/media/tac/x10a php bin/console folio:build --provider mus --gz --force --no-debug
php bin/console folio:publish --provider mus --repo museado/folios
```

Remote servers do not need the `hf` CLI for pulls. `folio:pull` lists public Hugging Face dataset files over HTTPS, downloads matching `<provider>/<code>.folio.gz` files, restores them to `${APP_DATA_DIR}/folio/<provider>/<code>.folio`, and inflates indexes, FTS, facets, and views.

```bash
# Pull one refreshed folio
APP_DATA_DIR=/platform php bin/console folio:pull --dataset fortepan/hu --force -vv

# Pull all folios for one provider
APP_DATA_DIR=/platform php bin/console folio:pull --provider mus --force -vv
```

A search error such as `no such table: item_facet_count` means the selected working `.folio` is stale or was not inflated with the current bundle. Repair by force-pulling the `.folio.gz` again. If the working file is otherwise correct and only derived tables are missing, `folio:fts:rebuild <provider>/<code>` recreates `item_fts`, `item_facet`, and `item_facet_count`.

After pulling into an app, run `dataset:scan --reset` (or the app-specific scan command) when the homepage/artifact registry needs to reflect the pulled folios.

## Current state (2026-05) — operational notes

- **mus**: 6 folios already fully populated in zm (`aust, auur, belvedere, larco, smk,
  walters`). Row counts match the normalize source (walters = 72,623).
- **mus/auur**: its `20_normalize` source is **gone** — its 15,041 folio rows have no
  source on disk. A blanket re-build/re-ingest of mus would wipe it. Re-build must be
  selective, or auur's source restored first.
- **dc**: 582 dataset dirs exist but are **empty scaffolding** (`05_raw` and
  `20_normalize` both empty, `folio/dc` empty). dc needs raw produced from the md side
  first, then `import:convert --provider dc --limit 0`, then `folio:build`.

## Implementation checklist

- [ ] Rename command `folio:ingest` → `folio:build`; keep an alias or update callers.
- [ ] `folio:build`: step-1 rows-only build → gzip to `folio-archive/` (`--no-archive`)
      and/or inflate to `folio/` (`--no-inflate`); both by default (`?bool …= null; ??= true`).
- [ ] Refuse `--no-archive --no-inflate`; empty-source guard + `--allow-empty`;
      freshness check + `--force`; `--dry-run`.
- [ ] Snapshot the `.gz` from the row-only DB *before* indexing.
- [ ] Add `folioArchiveRootDir` (`folio-archive/`) to `DataPaths`; path helper
      `<provider>/<code>.folio.gz`.
- [ ] `folio:publish`: upload existing `.gz` (build if missing); drop the deflate step.
- [ ] Provider → repo resolver (config map), default `museado/folios`, `--repo` override.
- [ ] Decide fate of `folio:archive` (keep for post-inflate re-archive, or drop).
- [ ] Survey callers of `folio:ingest`/`folio:archive`/`folio:publish` across harvest,
      md, zm, and the bundle before renaming. *(Not yet done.)*
- [ ] Castor task in zm wrapping scan → build → publish per provider / `--all`.
