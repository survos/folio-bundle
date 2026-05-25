# Survos Folio Bundle

Folio stores normalized/enriched dataset JSONL as portable SQLite archive files. It is the database, archive, and browsing layer for data that has already been normalized by dataset/import tooling.

`harvest` and `md` produce normalized JSONL. `folio:ingest` turns that JSONL into a standalone folio SQLite file. Consumers such as `zm` can use the Symfony/DataContracts stack when present, while Python/R/SQLite users can query the archive directly.

Required: `survos/field-bundle`, `survos/data-contracts`.
Suggested for ingest/write workflows: `survos/jsonl-bundle`, `survos/import-bundle`.

See `docs/configuration.md` for the required multi-connection Doctrine setup.
See `docs/archive-metadata.md` for the standalone archive metadata contract.
See `docs/presentation-layer.md` for the proposal to use folios as narrative institutional presentation packages.

## Archive Contract

A folio file stores canonical rows in `item` and self-describing metadata alongside them:

- `schema_table` and `schema_property` describe observed DTO types and fields in this archive.
- `schema_property.stats` stores field profile output from `survos/jsonl-bundle`'s profiler.
- `docs` stores generated JSON/Markdown documentation for humans, report writers, and AI agents.
- generated `dto_*` SQLite views project JSON fields into query-friendly columns.
- `term_set` and `term` store standalone controlled vocabularies and facets.

The metadata snapshot describes actual observed data, not the entire DTO contract universe. DTO classes from `survos/data-contracts` annotate observed fields with labels/descriptions when available, but consumers do not need PHP code to understand an archived folio.

## Search and Publication Notes

- `folio:ingest` loads rows, snapshots observed schema/docs/views, and rebuilds the SQLite FTS5 table `item_fts`.
- Existing folios can rebuild search with `bin/console folio:fts:rebuild <provider/dataset> --query="search terms"`.
- `folio:archive` refreshes archive metadata before packaging.
- FTS tables are derived data. Published archive files may drop `item_fts`, `VACUUM`, compress, ship, then rebuild FTS on the consuming side.
- SQLite views and docs are also derived from persisted metadata, but they are intentionally lightweight and useful for standalone consumers.
- Vector search is intentionally deferred. When added, start with a hybrid SQLite design: FTS5/BM25 for exact keyword strength, sqlite-vec for semantic retrieval, and Reciprocal Rank Fusion to merge ranks without normalizing incompatible score scales. Reference: https://ceaksan.com/en/hybrid-search-fts5-vector-rrf

## Direct SQLite Examples

```sql
select * from schema_table where kind = 'dto';
select * from schema_property where table_id = ? order by position;
select local_id, label, dto_type, dto_data, extras from item limit 20;
select * from dto_document limit 20;
select id, type, audience, body from docs order by position;
```

## TODO

- Add fieldSet support to the api-grid spreadsheet view to avoid displaying every DTO field at once.
- Rebuild views/docs on restore, not only FTS, if the archive was packaged without them.
