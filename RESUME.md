# Folio Bundle Resume Notes

## Current Goal

Make a folio a standalone, self-describing SQLite archive for normalized JSONL rows.

Folio replaces the useful archive/browser parts of Pixie without bringing back Pixie's hand-managed schema. Folio remains ORM-managed, but each archive stores enough metadata for non-PHP consumers to understand and query the file.

## Pipeline Context

- `harvest` and `md` create normalized/enriched JSONL.
- `folio:ingest` imports normalized/enriched JSONL into portable SQLite files.
- `zm` can import/show folios and gets richer Symfony/DataContracts behavior when available.
- Python/R/SQLite/reporting users should be able to inspect `schema_table`, `schema_property`, `docs`, generated `dto_*` views, and `item` directly.

Provider names are opaque data keys. Historical data may still use `mus/...`, but folio work should not depend on the old mus app code.

## Archive Metadata Direction

The metadata snapshot is data inside the folio, not a source-code snapshot of `survos/data-contracts`.

Important rules:

1. Snapshot observed schema, not every possible DTO field.
2. Use `survos/jsonl-bundle`'s `JsonlProfilerInterface` for field stats over final `item.dto_data` grouped by core + `dto_type`.
3. Use DTO reflection only to annotate observed fields with labels, descriptions, classes, and flags.
4. Store canonical DTO fields in `item.dto_data` and provider/source-specific fields in `item.extras`.
5. Generate `dto_*` SQLite views as convenience projections over JSON.
6. Store human/agent/machine docs in `docs` as JSON, Markdown, text, or HTML.
7. Keep term metadata standalone, borrowing useful concepts from `babel-bundle`'s `TermSet` / `Term` model but storing literal labels/descriptions.

See `docs/archive-metadata.md`.

## Current Implementation State

Work in progress in this branch:

- Added `Doc` entity/table.
- Added richer columns to `schema_table` and `schema_property`, including `stats` JSON.
- Enriched folio `TermSet` / `Term` toward the Babel shape.
- Reworked `FolioSchemaSnapshotter` to use `JsonlProfilerInterface` over observed DTO data.
- Added `FolioViewBuilder`, `FolioDocsBuilder`, and `FolioArchivePreparer`.
- Wired archive prep after ingest and before archive packaging.
- Updated `folio:ingest` to split DTO-shaped data from extras.
- Started updating the folio viewer to read persisted schema/docs/views.

PHP syntax check has passed for `src/**/*.php`, but functional testing is still pending.

## Next Safe Steps

1. Review the current diff for design and style cleanup.
2. Fix any service wiring issues from the new `JsonlProfilerInterface` dependency.
3. Decide whether `folio:restore` should run full archive preparation or only rebuild FTS.
4. Run Twig lint.
5. Run an ingest against a small `harvest` or `md` dataset.
6. Inspect the SQLite file:

```sql
select * from schema_table;
select table_id, name, type, stats from schema_property order by table_id, position;
select id, type, audience from docs order by position;
select name from sqlite_master where type = 'view' and name like 'dto_%';
```

7. Open the folio viewer and verify docs/schema/views render usefully in `zm` and the bundle viewer.

## Verification Commands

```bash
find src -name '*.php' -print | sort | xargs -n1 php -l
php bin/console lint:twig templates/
php bin/console folio:migrate harvest/cleveland --force
php bin/console folio:ingest harvest/cleveland --core=obj
php bin/console folio:archive harvest/cleveland
```

Use real dataset keys that exist in the host app's `DatasetInfo` table.
