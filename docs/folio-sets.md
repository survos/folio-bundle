# Folio sets: which folios an app shows

Status: 2026-09-28. Tags, the resolver and `folio:sets:sync` are implemented and resolve against a
local registry. zm's `/folio/list.json` has a `tags` field, but on production (recordia.org) all
2,853 folios publish `tags: []`: registrations were never re-synced after tags landed, so no app
can select by tag yet. The resolver still reads the local registry. Next: sets gain hosts and
become sites (see "Sites" below); nothing has moved onto them yet.

This bundle no longer requires dataset-bundle (see [bare-app.md](bare-app.md)), which is what makes
a set usable by an app that has no registry at all — once the resolver reads the catalog.

## The problem

Every reading app (zm, fotostory, ink, mastheads) needs a set of folios. Today each one answers
"which folios?" differently, and the answers drift:

| App | Mechanism | How it goes wrong |
|---|---|---|
| zm | `FolioRegistration` rows, created by `folio:sync` from dataset artifacts | fine for "everything", no notion of a subset |
| fotostory | `folioset:save` / `folioset:load`, "ported verbatim from zm" | the same code twice; editorial sets are folio codes hand-listed in `config/services.yaml` |
| ink | its own publication register, `ink:publications:add` / `:sync`, composer `sync` script | a third mechanism, add-by-hand |

The hand-listed YAML exists for two good reasons, both recorded in fotostory's
`FolioSetLoadCommand`: no rule in the data picks out an editorial grouping like "the
Fortepan-method community archives", and a set that lived only in production's database was lost
(2026-09-14). The fix keeps both properties — editorial, and in the repo — without naming folios.

## The model

1. **Tags are a dataset fact.** A dataset carries tags (`DatasetConfiguration::$tags`), set where
   the dataset is defined, the same place as its provider and content type. They travel with it:
   `_meta/dataset.json` → registry (`DatasetInfo::getTags()`) → `/api/dataset_infos` (`tags`).
   Harvest assigns them in code when it writes a dataset's metadata; there is no UI.
   An editorial decision ("fortepan-method", "newspaper-source") is a tag, not an app's list.
   Tags are lowercase kebab-case, de-duplicated and sorted (`DatasetConfiguration::normalizeTags`).

2. **A folio set is a code, a label and criteria.** Criteria never name folios:

   ```yaml
   survos_folio:
       folio_sets:
           sources:
               label: Newspaper sources
               criteria:
                   tags: [newspaper-source]        # any of these
                   # tagsAll: [a, b]               # all of these
                   # provider: [survey, cron-america]
                   # contentType: [newspaper]
                   # minRows: 1
               core: obj
   ```

   Sets live in the app's config, so a fresh checkout recreates them.

3. **One implementation, here.** A single command, `folio:sets:sync`, resolves each set's criteria
   against the dataset registry, records membership, and (later) pulls matching folios and builds
   the pooled search index when a set asks for one. An app that shares harvest's data root points
   `survos_dataset.registry_database_path` at harvest's registry; a remote app will read
   `<folio_server>/api/dataset_infos` (the API Platform resource, not zm's hand-built `list.json`). It is idempotent and runs as a composer
   `auto-script`, so every install and deploy reconciles. When the catalog is unreachable it keeps
   the last membership and warns, rather than emptying the set (ink's `FolioCatalog` already does
   this for its catalog cache).

4. **Membership is derived.** It is rebuilt from criteria every sync and is never edited or trusted
   as the source of truth. Nothing is "added" to a set; a dataset joins by gaining a tag.

## What it replaces

- fotostory: `folioset:save`, `folioset:load`, `app.folio_sets` folio lists → criteria (editorial
  sets become tags on their datasets; beacon sets become `provider:` criteria).
- zm: its copy of `folioset:save`; `FolioRegistration` sync stays (it is the catalog, not a set).
- ink: `ink:publications:add` / `:sync` → one `periodicals` set; the composer `sync` script calls
  `folio:sets:sync` instead of `ink:sync`.
- mastheads: one `sources` set, `tags: [newspaper-source]`.

Membership is recorded in `var/folio-sets/<code>.json` (`FolioSetResolver::members()` reads it).
Apps that need database rows for a set (fotostory's search-index names) derive them from that file.

## Implemented

- `DatasetConfiguration::$tags`, `withTags()`, `hasTag()`, `normalizeTags()` (dataset-bundle).
- `DatasetInfo::getTags()`, read from the cached metadata, in the `dataset:read` API group; no
  schema change (dataset-bundle).
- `survos_folio.folio_sets` config, `Set\FolioSetResolver`, and `folio:sets:sync` (this bundle).
  Content type comes from the artifact's recorded `contentType`, else from the folio's own
  `schema_table.dto_type` (so a multi-core cron-america folio is both `newspaper` and `document`).
- harvest `survey/us-newspapers` is tagged `newspaper-source`; mastheads' `sources` set resolves to it.

Checked against harvest's registry (103 folios): `contentType: newspaper` → 73,
`contentType: photograph` → 11, `provider: cron-america` → 63, `tags: newspaper-source` → 1.

## Not yet

- `FolioSetResolver` reading the remote catalog. The DATA is published now (`/folio/list.json`
  carries `tags`, sourced from zm's own `FolioRegistration.tags` rather than a live dataset-bundle
  lookup), but `resolve()` still requires `DatasetInfoRepository` and throws without it. An app
  without a registry works only from a recorded `var/folio-sets/<code>.json`.
- Pulling member folios and building pooled search indexes from a set.
- Migrating fotostory and ink onto sets, then deleting their set commands.
- This bundle's generic pages need packages it does not declare: `simple_datatables` (collection),
  the `imgproxy` Twig filter (folio page), a `folio_fts` search configuration (search page). Either
  declare them or make those pages degrade without them.

## Worked example: an oral-history site

The point of a tag is that a site is a *criteria* over source metadata, not a hand-kept list.
Voxstory selects recorded first-person testimony wherever it came from:

```yaml
survos_folio:
    folio_sets:
        voices:
            label: Oral histories
            criteria:
                tags: [oral-history]
```

Harvest assigns that tag when it writes each dataset's metadata — every LOC collection gets it
automatically (`App\Command\LocRawCommand::ORAL_HISTORY_TAG`), and a dataset can add narrower tags
of its own via `tags:` in `config/loc/datasets.yaml`. The tag is deliberately provider-neutral: a
Densho or StoryCorps dataset carrying `oral-history` joins the same set rather than needing one set
per provider.

A single-collection site is the same mechanism with narrower criteria — `provider: [mus]` plus a
tag, or a tag minted for exactly that grouping (`nabolom`), which is how one brand limits itself to
its own few folios.

## Sets and sites, owned by the bundle (proposed 2026-09-28)

Every reading site is the same structure, rebuilt in each app: a host, a rule for which folios
belong, optional per-folio editorial choices, and a check that its pages work.

| Site | Host → site | Membership | Editorial per folio | Check |
|---|---|---|---|---|
| covid.voxstory.org | fotostory `SubdomainTenantListener` | `app.vox_sets` (tag half-working) | — | none |
| fotostory tenants/sets | `SubdomainTenantListener` | `app.folio_selectors`, `app.folio_sets`, `AppLoadCommand` | hero scrape | none |
| tobacco.survos.com | zm `TobaccoSiteListener` | hardcoded `n4/tobacco` | — | none |
| ink | its host | `Publication` rows | slug, order, hidden, title | `ink:smoke` |

What four hand-kept lists cost on 2026-09-28: voxstory interview pages 500'd and its set pages
503'd, fotostory served a cleveland "Item not found" from an index built off a different copy
than the one on disk, and a fresh checkout could pull only 9 of fotostory's photo tenants.
Nothing noticed; only ink checks its own pages.

folio-bundle owns two concepts, both defined in the app's config (so a fresh checkout recreates
them) and both selecting folios the same two ways — by **tag** or by **folio name**:

- **Folio set** — a code, a label and criteria. The only place criteria live.
- **Site** — hosts plus the sets it shows. No criteria of its own.

```yaml
survos_folio:
    folio_sets:
        oral-history:
            label: Oral histories
            criteria: { tags: [oral-history] }
        covid:
            label: COVID-19 American History Project
            criteria: { folios: [loc/covid-19-american-history-project] }
        opan:
            label: OPAN Global
            criteria: { tags: [opan] }
        museums:
            label: Museum Collections
            criteria: { tags: [museum] }       # tag not assigned in harvest yet
        tobacco:
            label: Tobacco News
            criteria: { folios: [n4/tobacco] }

    sites:
        vox:
            hosts: [voxstory.org, vox.wip]
            sets: [oral-history]
        covid:
            hosts: [covid.voxstory.org, covid.vox.wip]
            sets: [covid]
        fotostory:
            hosts: [fotostory.org, fotostory.wip]
            sets: [opan, museums]         # the home page lists each set
        museums:
            hosts: [museums.fotostory.org, museums.fotostory.wip]
            sets: [museums]
        tobacco:
            hosts: [tobacco.survos.com, tobacco.wip]
            sets: [tobacco]
```

A site's folios are the union of its sets' members. A set with no site is still useful — a
search scope, an index, a page under `/set/{code}` on whatever site links to it.

**A set of one is the folio.** When a set resolves to exactly one member, its site behaves as that
folio's own site rather than a collection of one:

- the set's home is the folio's home — no "1 collection" landing page, no "All collections" link;
- search uses the folio's own search (FTS or the shared row index); no pooled set index is built;
- routes can drop the folio code (`covid.voxstory.org/interview/{id}`, not
  `/folio/loc/covid-19-american-history-project/obj/interview/{id}`), while the long form keeps
  working and redirects.

It is decided by the resolved membership at sync time, not by how the set is written: a `folios:
[n4/tobacco]` set is always one, and a tag set that has one member today gets the same treatment
and becomes a real collection on the sync where a second folio gains the tag — no config change.
Everything that renders a set asks the set (`isSingle()` / `single()`) rather than counting.

`criteria.folios` is for the one- or few-folio case (tobacco, a vox subdomain). Anything that
should grow on its own is a tag, set in harvest; `tags` and `folios` may be combined, and the
existing `tagsAll` / `provider` / `contentType` / `minRows` criteria stay available.

The bundle owns:

1. **Host → site.** A request listener sets the site (and so its sets) on the request and 404s a
   folio outside them.
   Replaces `TobaccoSiteListener`, vox's subdomain handling, and the folio half of
   `SubdomainTenantListener`.
2. **`folio:sites:sync`.** Resolves every set's criteria against `<folio_server>/folio/list.json`, keeps the
   last membership when the hub is unreachable, and pulls members where the app keeps its own
   copies. A composer auto-script and postdeploy step, so every install reconciles.
3. **`folio:sites:smoke`.** Requests each site's home, every member folio, and one row per folio;
   fails on a non-200 or an empty row. Runs after deploy with `--notify`. `ink:smoke` becomes a
   caller.

The app keeps only decoration: a hook keyed by site + folio for ink's slug/order/hidden/title and
fotostory's hero content. It never decides membership.

### Sync: files, then the app database

`folio:sites:sync` does two separate things, and they obey different rules.

**Files** (the `.folio` on disk):

| Invocation | Missing folio | Existing folio |
|---|---|---|
| `folio:sites:sync` | reported, not fetched | left alone |
| `--pull` | `folio:pull` | left alone |
| `--force` (implies `--pull`) | `folio:pull` | re-pulled and re-inflated |

`local_passthrough` overrides all three: the app does not own its data dir (production fotostory
and ink read zm's `/platform`), so it never writes a folio file there. Passthrough is file-only:
folio files may be shared, the dataset registry may not. Harvest alone owns the registry, and
`folio:pull` no longer writes to any registry at all — not under passthrough, not otherwise; a
consumer learns what exists from the Harvest API/feed. When `--pull`/`--force` is refused for that reason, sync says so as a warning
naming the folio; today `folio:pull` prints a plain "skipping fetch" line even under `--force`,
which is how a laptop with passthrough hardcoded on drifted from the published folios unnoticed.
Passthrough is an env value (`FOLIO_LOCAL_PASSTHROUGH`): true where the builder shares the disk,
false on a laptop that pulls its own copies.

A re-pull must inflate to a temp file and rename it into place, as `folio:build` does, so a reader
never opens a half-written folio. Today it does not: `FolioArchiveService::restore()` gunzips
straight onto the target and inflates it in place, so `--force` on a folio being served is unsafe
until that changes. "Stale" (re-pull only what changed upstream) waits until the
catalog publishes checksums; today `checksum` is null, so `--force` means everything.

A member the catalog lists but cannot serve (404, unpublished) is reported and kept in
membership marked unavailable — the site hides it and smoke flags it, rather than a page failing
at request time.

**App database** (tenants, publications — whatever the app keeps per folio): always reconciled,
passthrough or not, from membership plus what is actually on disk. Present members are upserted
through the app's hook; members that are gone are removed or hidden. fotostory's `tenants:load`
does the upsert half today (it checks the file itself and upserts `Tenant`/`TenantFolio` even
when it skips the pull) but never removes, which is why its home page logs "Skipping tenant" on
every request instead of not listing the folio. That generic half moves here; fotostory keeps
only brand, country and the hero scrape as its hook.

### Composer

Every reading app gets the same two script entries, so no deploy or checkout depends on anyone
remembering a command:

```json
"scripts": {
    "auto-scripts": {
        "folio:sites:sync": "symfony-cmd"
    },
    "sync": "@php bin/console folio:sites:sync --no-interaction"
}
```

- `composer install` / `update` (auto-scripts): membership + app database, no downloads. Safe on
  production and never slow.
- `composer sync -- --pull`: a laptop or fresh checkout gets exactly what is published.
- `composer sync -- --force`: refresh everything local.
- Dokku postdeploy: `composer sync && php bin/console folio:sites:smoke --notify`, as ink does
  now with `ink:sync` / `ink:smoke`. A catalog outage keeps the last membership and does not fail
  the release (ink learned this: a non-zero postdeploy rejects the deploy).

`criteria.folios` exists for the one-folio site (tobacco, a vox subdomain). Anything wider is a
tag, set in harvest.

Order: resolver reads the remote catalog + smoke → vox (smallest) → tobacco → fotostory → ink.

## Decided

Tags are assigned programmatically in harvest, where each dataset's metadata is written. No UI.
If zm ever edits tags, they need their own field: the registry refresh replaces the stored
metadata wholesale (`DatasetRegistryUpdater::populateFromMeta()`), which would wipe them.
