# Folio sets: which folios an app shows

Status: 2026-09-23. Tags, the resolver and `folio:sets:sync` are implemented and resolve against a
local registry. zm's `/folio/list.json` now publishes each folio's `tags`, so a remote catalog can
be read; the resolver itself still reads the local registry. Moving fotostory and ink onto this is
not done yet.

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

## Decided

Tags are assigned programmatically in harvest, where each dataset's metadata is written. No UI.
If zm ever edits tags, they need their own field: the registry refresh replaces the stored
metadata wholesale (`DatasetRegistryUpdater::populateFromMeta()`), which would wipe them.
