# Folio properties: migration design

Status: implemented locally, 2026-10-04. No release or deployment.

Shared metadata contracts live in `lib/data-contracts`; see its
`docs/dataset-metadata.md` for vault ownership, overrides and durable storage.
`lib/folio` (`survos/folio`) provides framework-free PDO metadata reading,
conversion and catalog projection. `folio-bundle` supplies Doctrine/Symfony
integration. This extracts metadata first, not every existing row/query service.

The new library is unreleased. Local harvest, ink and zm use Composer-created
symlinks plus `../mono/link .`; application lockfiles were not upgraded for this
unreleased dependency. Release the library/contracts and bundle together only with
approval, then resolve application dependencies and clear Doctrine metadata caches.
Do not run a routine dependency install that removes the local library before that
coordinated release, unless intentionally rolling the linked bundle back too.

## Storage and API

Keep `folio` as the identity/bootstrap row: `code`, `datasetKey`, `ftsContent`.
Add `folio_property`: `key` (string primary key), `value` (JSON encoded TEXT),
`source` (`meta`, `build`, `human`, `import`), `owner` (stable writer ID),
`updatedAt` (UTC), and JSON `provenance` (origin/reference/transformation details).
Each file has one folio, so properties need no additional folio foreign key.
Claim remains record-scoped and unchanged.

Keep public typed access such as `$folio->label`, `$folio->description`,
`$folio->contentType`, `$folio->tags`, and `$folio->rowCount`; add `get($key)`
and an explicit source-aware write API. Preserve direct Doctrine `find(Folio::class)`
callers, not only callers of a new reader service. Property hydration/persistence
must work with the switching entity manager and clear per-file state on switches.
Do not make an unconditional ORM relationship load a table absent in legacy files.
The new ORM mapping must select only bootstrap columns; old readers that still
select descriptive columns cannot read newly built thin folios.

`rowCount` is build-derived metadata, not identity. Move it to properties and
audit raw SQL/count consumers before implementation; preserve its integer default
of zero. FTS bootstrap continues to use `ftsContent` directly.

## Initial key registry

Top-level compatibility keys retain their existing spelling; title facts use the
`title.` namespace. Types below are decoded JSON types. Nullable descriptive
values are permitted; absence and explicit JSON null remain distinguishable.

| Key | Type | Normal writer |
| --- | --- | --- |
| `schemaVersion` | integer; 2 for this property format | build/migration |
| `label`, `description`, `contentType` | string or null | meta |
| `tags` | list of strings | meta |
| `rowCount` | nonnegative integer | build |
| `title.source` | URL string | meta |
| `title.title` | string or list of strings | meta |
| `title.essay` | string | meta |
| `title.essayContributor`, `title.datesOfPublication` | string or list of strings | meta |
| `title.firstIssue`, `title.lastIssue` | YYYY-MM-DD string | meta |
| `title.issueCount` | nonnegative integer | meta |
| `title.frequency`, `title.createdPublished`, `title.notes` | string or list of strings | meta |
| `title.subjectHeadings`, `title.precedingTitles`, `title.oclc` | string or list of strings | meta |

Map each `extras.titleRecord` member to `title.<member>` without joining lists or
discarding structure. Harvest currently passes several LOC fields through without
normalization; verify actual cached records before enforcing narrower types.
Unknown keys accept JSON scalars, lists, objects, and null, and round-trip without
loss. Document new shared keys here; use namespaced keys for extensions. Reject
invalid JSON explicitly rather than silently substituting a legacy value.
`summary` remains the catalog's existing structured summary, not an alias for
description. Reserve `schemaVersion` and `rowCount` for system facts.

## Read both, write new, convert lazily

Readers never migrate. Inspect table/column availability without writing. Prefer
the property when present, including explicit JSON null. For an unconverted file,
fall back per missing key to available legacy columns (`label`, `content_type`,
`row_count`), then typed defaults. This also covers an empty properties table
created by the existing automatic schema updater before data conversion.

Conversion runs transactionally on an explicit migration or authorized writable
touch: create the property table, copy legacy values only for absent keys, then
write `schemaVersion = 2` last. Existing properties always win. Legacy-only files
are reported as format 1 without writes. Once format 2 is committed, absent
properties use defaults, not stale legacy columns. Repeated conversion is a no-op.
Retain old physical columns on migrated files; no table rewrite or bulk rebuild.
New files need only bootstrap columns plus the properties table.

The existing `PRAGMA user_version` is a checksum of Doctrine-generated schema SQL,
not a monotonic format version. Keep it separate from the semantic `schemaVersion`.
`folio:migrate` currently also overwrites label from the dataset registry; conversion
must preserve the folio's existing value instead of treating migration as a rebuild.

## Ownership and rebuilds

A key has one current owner, not a history of competing values. A writer replaces
its own owner's snapshot (including removing vanished keys) and leaves other
sources untouched. Human edits may explicitly take ownership of descriptive keys;
automated writers cannot overwrite them. Legacy conversion records copied values
as `import`; a subsequent build may explicitly adopt the known legacy-derived
metadata keys into `meta`/`build`, rather than making all imported values disposable.
Only the known legacy Folio keys may be adopted automatically; other owners remain protected.

The current ingest path calls `reset()` before writing rows. Preserve foreign-owned
properties, including unknown keys, before reset and restore them into the rebuilt
file. A failed build must not destroy the only durable copy of human metadata;
atomic builds retain the old file until success; direct reset retains a durable
`.metadata-backup.json` recovery snapshot.
Preserve provenance/timestamps on untouched values. Source replacement and format
conversion must be transactional and tested for interruption/repetition.

## Catalog and consumer compatibility

`zm/src/Service/FolioCatalog.php` builds `list.json` from DatasetInfo/Artifact,
not from SQLite. Feed a property projection into the build/scan metadata path:
at least description, plus label/title, tags, contentType and rowCount. Preserve
existing catalog field names and summary shape. Catalog generation consumes that
projection; serving each request must not open roughly 2,900 databases. Existing
files can be projected read-only with the same fallback reader.

Ink already accepts catalog description and uses it for publication metadata.
Rut uses the typed bundle catalog entry; fotostory also has direct Folio entity
reads and a separate DatasetInfo API client. Pressia has no matching source-level
Folio integration in this review. Build events and dataset scans now carry `folioProperties` in Artifact metadata;
zm projects them into the catalog, including titleRecord and description. Its
query filter searches the projected descriptions and title records in the catalog. Paper-level search
should consume catalog metadata rather than fan out across folio files.

## Rollout and fallback lifetime

1. Implement/test dual readers, including direct Doctrine and raw-SQL callers,
   while existing writers still produce the old format.
2. Upgrade every reader of shared files: harvest, ink, zm/museado, fotostory,
   pressia where applicable, rut's catalog dependency, and background workers.
   Record deployed bundle versions and rollback compatibility. This is a future
   deployment checklist, not permission to deploy.
3. Only after reader readiness is confirmed, enable new-format builds and lazy
   migration. Keep writer activation separate from reader rollout; no release,
   tag, push or deployment is authorized by this design.
4. Keep the legacy fallback throughout the current bundle major and the next
   major. Removal requires a later major, an explicit inventory showing no
   supported legacy files (including archives), and a separately approved plan.
   No deadline forces a bulk migration of the existing approximately 2,900 files.

## Implementation checkpoints and verification

Commit small steps: reader/entity/registry and legacy fixtures; idempotent conversion
and ownership; metadata-aware builds; catalog projection. Cover absent/empty tables,
partial conversion, explicit nulls, unknown JSON values, human overrides, source
deletion, failed rebuild preservation, and switching between legacy/new files.
Run bundle tests and harvest/ink suites against `../mono/link .`, not vendor copies.
Check branches and stage only task-owned files before each local commit.

For real-file verification, obtain a consistent read-only snapshot of one built
newspaper folio in scratch storage, migrate only that copy, and compare metadata,
row/core counts, representative records, SQLite integrity and search behavior.
Never open a live folio through the writable self-migrating context for this check.
No live rebuild, deletion, migration or publication is part of verification.


## Local verification (2026-10-04)

- Shared contracts, dataset metadata and bundle tests cover legacy/new reads,
  incomplete conversion, nulls, unknown JSON objects, ownership, vault recovery,
  overrides, rebuild preservation and the `folio:migrate --file` command.
- Full harvest and ink suites run against linked sources. Harvest's installed
  Rector/PHPUnit bootstrap collision is avoided without vendor edits by loading
  `vendor/autoload.php` before `vendor/phpunit/phpunit/phpunit`.
- A read-only SQLite backup of X10's `cron-america/sn85049620` (The Cedar Co.
  news-letter) was converted with harvest's actual `folio:migrate --file` command.
  All 3,480 records, 3,489 pages, two cores and claims retained identical hashes;
  integrity remained `ok`, and FTS counts for news/county/river stayed 69/61/12.
- X10 scratch builds exercised 200 synthetic records and 71 actual newspaper
  records from sn88059517, with human overrides surviving rebuild and a discarded
  build leaving the prior file unchanged. Original folios were not modified.
- No bulk migration, release, tag, push, publish, or deployment was performed.
