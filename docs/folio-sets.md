# Folio sets: which folios an app shows

Status: 2026-09-22. Tags, the resolver and `folio:sets:sync` are implemented and resolve against a
local registry. Reading a remote catalog, and moving fotostory and ink onto this, are not done yet.

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

- Reading a remote catalog (`/api/dataset_infos`) for apps that do not share harvest's registry.
- Pulling member folios and building pooled search indexes from a set.
- Migrating fotostory and ink onto sets, then deleting their set commands.
- This bundle's generic pages need packages it does not declare: `simple_datatables` (collection),
  the `imgproxy` Twig filter (folio page), a `folio_fts` search configuration (search page). Either
  declare them or make those pages degrade without them.

## Decided

Tags are assigned programmatically in harvest, where each dataset's metadata is written. No UI.
If zm ever edits tags, they need their own field: the registry refresh replaces the stored
metadata wholesale (`DatasetRegistryUpdater::populateFromMeta()`), which would wipe them.
