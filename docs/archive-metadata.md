# Folio Archive Metadata

Folio archives are portable SQLite files for normalized dataset rows. The canonical storage table remains `item`: rows keep a stable local id, label, DTO type, DTO-shaped JSON, and extra source fields. The archive also stores enough metadata for a consumer to understand the file without having the PHP DTO library installed.

This metadata is a snapshot of the observed archive, not a copy of `survos/data-contracts` source code and not the full contract universe.

## Producers and Consumers

`harvest` and `md` create normalized JSONL. `folio:ingest` loads that JSONL into a folio SQLite file. Consumers such as `zm` get the richer Symfony stack for free when available, but a Python/R/SQLite user should still be able to inspect the archive and write reports directly.

Provider names are data keys. Some historical folios may still use `mus/...`, but folio code should treat provider/dataset keys as opaque and must not depend on the old mus application.

## Observed Schema

Folio exposes virtual schema metadata through these tables:

- `schema_table` describes archive-visible row groups. DTO groups use `kind = 'dto'` and are named like generated SQLite views, e.g. `dto_document` or `dto_per_person`.
- `schema_property` describes observed fields in each schema table.

The snapshotter groups final rows by core and `dto_type`, then profiles only the observed `item.dto_data` rows in each group. This keeps the archive focused on the fields this dataset actually contains. Fields from the DTO contracts that never appear in the dataset are not included.

`schema_property.stats` stores the existing `survos/jsonl-bundle` profiler output, including totals, null counts, distinct counts, inferred types, storage hints, string length stats, boolean/url/json/image/natural-language hints, split candidates, and array stats.

DTO reflection is used only as an annotation source for observed fields:

- `ClassMeta` / docblocks annotate `schema_table.label` and `schema_table.description`.
- `PropertyMeta`, `Field`, and docblocks annotate `schema_property.label`, `description`, and search/facet/sort flags.
- `schema_table.dto_class` and `schema_property.declaring_class` are provenance hints, not required for consumers.

## DTO Data and Extras

During ingest, normalized rows are split into:

- `item.dto_data`: fields that belong to the resolved DTO shape and have values.
- `item.extras`: normalized source/provider fields that do not belong to the DTO shape.

This makes the observed schema a description of canonical DTO data while preserving source-specific fields for inspection and future use.

## Generated Views

Folio can generate SQLite views from observed schema metadata. These are convenience projections over `item`, not authoritative storage.

Example:

```sql
CREATE VIEW dto_document AS
SELECT
  local_id,
  label,
  dto_type,
  substr(core_id, instr(core_id, ':') + 1) AS core_code,
  json_extract(dto_data, '$.title') AS title,
  json_extract(dto_data, '$.description') AS description
FROM item
WHERE dto_type = 'document'
  AND substr(core_id, instr(core_id, ':') + 1) = 'obj';
```

A non-PHP consumer can then query:

```sql
select title, description
from dto_document
where title is not null
limit 20;
```

Views are rebuildable derived metadata. Archives should keep them when useful, but consumers can recreate them from `schema_table` and `schema_property`.

## Docs Table

The `docs` table stores human-, agent-, and machine-readable documentation inside the SQLite file.

Expected generated rows:

- `meta` with `type = 'json'`: machine-readable folio metadata, row counts, DTO tables, views, generation metadata.
- `overview` with `type = 'md'`: human/agent summary of the folio.
- `schema` with `type = 'md'`: observed schema and field descriptions.
- `query-guide` with `type = 'md'`: SQLite examples for report writers.

The structured metadata tables remain authoritative for queries. The docs table explains and summarizes them for humans and AI agents.

## Terms

Folio has standalone `term_set` and `term` tables for vocabularies, facets, classifications, and other controlled lists. Their shape follows the useful parts of `babel-bundle`'s richer model while storing literal labels/descriptions so the archive remains standalone:

- `term_set`: code, label, description, rules, meta, enabled.
- `term`: code, path, parent, label, description, rules, meta, enabled, sort.

Unlike Babel, folio does not require translation string tables for labels. Symfony consumers can still map or enrich terms with Babel when available.

## Archive Preparation Lifecycle

After ingest and before publication, folio should prepare the archive in this order:

1. Snapshot observed schema and field stats.
2. Rebuild generated DTO views.
3. Regenerate docs rows.
4. Rebuild FTS5 search indexes.

Before creating a compressed archive, folio should rerun the same metadata preparation, then drop rebuildable heavy indexes such as FTS if desired, `VACUUM`, and gzip the SQLite file.

On restore, consumers should rebuild derived indexes and may rebuild views/docs from the persisted metadata if needed.
