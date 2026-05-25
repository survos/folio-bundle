# Folio-Backed ARK Server

A folio can serve as a stable backing store for ARK endpoints. Any `Row` that carries an ARK identifier can be resolved from the folio SQLite file, rendered in the requested language, and served without depending on the rest of the producer application.

This is a bonus of the folio format: the endpoint data can be separated from the operational system that harvested, normalized, enriched, or edited it.

## Why Folios Work Well for ARKs

ARKs are long-lived identifiers. The endpoint behind an ARK should be boring, durable, and stable even when the main application changes.

A folio gives us that shape:

- the canonical row data is packaged in SQLite;
- observed schema metadata is packaged with the data;
- docs and query guidance are packaged with the archive;
- generated views make report/query access simple;
- FTS and other derived indexes can be rebuilt;
- the file can be deployed independently of the source pipeline.

The harvester/importer/editor stack can evolve. The ARK endpoint can keep serving a frozen or deliberately versioned folio.

## ARK-Carrying Rows

An ARK may appear in canonical DTO data or in source-specific extras. Consumers should check both:

- `item.dto_data` for canonical fields such as `ark`, `identifier`, `citation`, or dataset-specific DTO fields;
- `item.extras` for provider-specific ARK fields preserved during ingest;
- generated `dto_*` views when the ARK field is part of observed DTO metadata.

A practical lookup can be implemented with SQLite JSON functions:

```sql
select local_id, label, dto_type, dto_data, extras
from item
where json_extract(dto_data, '$.ark') = :ark
   or json_extract(dto_data, '$.identifier') = :ark
   or json_extract(extras, '$.ark') = :ark
limit 1;
```

The exact field names should be documented in `schema_property` and in the generated `docs` rows for the folio.

## Language-Aware Serving

A folio row can be served in any language supported by the consumer. There are several levels of language support:

1. **Source language only**: serve values exactly as stored in `dto_data`.
2. **Pre-enriched multilingual values**: serve translated fields already present in the folio, if the pipeline stored them.
3. **Consumer-side translation stack**: applications such as `zm` can use Babel/DataContracts/translation services to render labels, descriptions, templates, and term values in the requested locale.
4. **Static language snapshots**: a publication system may build one folio or one docs/view layer per target locale for fully static deployment.

The key point is that ARK resolution does not require the original producer app. The resolver only needs the folio file plus whatever optional language service the deployment wants to provide.

A route shape might be:

```text
/id/{ark}
/id/{ark}.{format}
/{locale}/id/{ark}
/{locale}/id/{ark}.{format}
```

Where `format` can be `html`, `json`, `jsonld`, or another representation.


## Folio as a Presentation-Layer Format

The same properties that make folios useful for ARK endpoints also make them useful as a general presentation-layer data format.

Most production systems have two very different jobs:

1. **Operational work**: harvesting, normalization, enrichment, editorial review, workflow, queues, migrations, AI calls, media dispatch, permissions, and admin screens.
2. **Presentation work**: serve stable records, browse pages, search, cite, export, answer questions, and support public identifiers.

Those jobs should not always depend on the same live database. The operational database changes often and carries application complexity. The presentation layer wants stability, speed, and a small surface area.

A folio is a clean boundary between those worlds. It is a compact, queryable, self-describing snapshot built from the operational pipeline but safe to deploy separately.

## Full-Text Search

Folio ships well with SQLite FTS5. The canonical rows remain in `item`, while `item_fts` is a rebuildable derived index.

That means a presentation app can offer search without Meilisearch, Postgres, or the original Symfony app:

```sql
select d.local_id, d.label, d.dto_type, bm25(item_fts) as score
from item d
join item_fts f on f.rowid = d.rowid
where item_fts match :query
order by score
limit 25;
```

This is enough for many public collection pages:

- search within one collection;
- filter by DTO type;
- show snippets from title/description/search summary;
- link to a row page or ARK page;
- rebuild the index after restore.

For small and medium archives, this keeps the presentation deployment simple. A static-ish site, a Python Flask app, a Symfony viewer, or a command-line report can all query the same SQLite file.

## RAG and Retrieval

Folio is also a good Retrieval-Augmented Generation boundary.

The archive already has the pieces a RAG system needs:

- stable row ids;
- labels and citations;
- `dto_type` and core information;
- canonical `dto_data`;
- field-level descriptions in `schema_property`;
- dense summaries or search summaries when generated upstream;
- FTS5 for keyword retrieval;
- optional future vector tables for semantic retrieval;
- docs rows that tell an agent what the archive contains.

A basic RAG flow can run entirely against a folio:

1. Use FTS5 to retrieve candidate rows.
2. Optionally rerank or combine with vector search.
3. Build context from `label`, `dto_type`, selected `dto_data` fields, citations, and schema descriptions.
4. Ask the model to answer with row citations.

Example retrieval query:

```sql
select
  d.local_id,
  d.label,
  d.dto_type,
  json_extract(d.dto_data, '$.title') as title,
  json_extract(d.dto_data, '$.description') as description,
  json_extract(d.dto_data, '$.denseSummary') as dense_summary,
  bm25(item_fts) as score
from item d
join item_fts f on f.rowid = d.rowid
where item_fts match :query
order by score
limit 12;
```

The RAG layer does not need to know the producer application's entity model. It can read `schema_table`, `schema_property`, and `docs` to understand the dataset.

## Examples

### 1. Public Collection Microsite

A museum publishes a collection as `provider/collection.folio.sqlite.gz`. A small viewer app restores it and serves:

- `/` collection overview from `docs.overview`;
- `/search?q=...` from FTS5;
- `/type/photograph` from `dto_photograph`;
- `/id/{ark}` from an ARK lookup view;
- `/data/schema` from `schema_table` and `schema_property`.

The main harvesting system can be offline and the site still works.

### 2. Partner Data Delivery

A partner wants a local research copy but does not run Symfony. They receive the folio and open it with Python:

```python
import sqlite3

conn = sqlite3.connect('collection.folio.sqlite')
rows = conn.execute('select title, date, description from dto_document limit 100').fetchall()
```

They can also inspect `docs.query-guide` and `schema_property.stats` to learn which fields are populated and useful.

### 3. AI Research Assistant

An AI assistant receives only the folio file. It first reads:

```sql
select id, type, body from docs order by position;
select name, dto_type, row_count from schema_table where kind = 'dto';
select table_id, name, description, stats from schema_property order by table_id, position;
```

Then it uses FTS5 to retrieve rows and answer questions with citations. The `docs` table acts as durable prompt context inside the archive.

### 4. Static Publication with Dynamic Search

A static site generator reads the folio and emits HTML pages for each row. The same folio is shipped next to a small search endpoint or WebAssembly SQLite client for local search. The publication artifact remains traceable back to one archive file.

### 5. Versioned Exhibitions

An exhibition can pin a specific folio version. Curators can rebuild the operational data later, but the exhibition keeps serving from the exact archive snapshot used at launch. If needed, a newer folio can be published as a deliberate version upgrade.

## Why Not Query the Main Database Directly?

For internal admin screens, querying the main database is fine. For public presentation, long-lived identifiers, and partner delivery, the main database is often the wrong boundary.

A live operational database brings risks:

- schema migrations can affect public pages;
- queues and enrichment jobs can leave records mid-transition;
- search indexes may be rebuilding;
- access-control or admin assumptions can leak into presentation code;
- downstream users need too much application context.

A folio is simpler:

- one file;
- stable schema;
- observed metadata;
- rebuildable indexes;
- readable docs;
- direct SQL access;
- easy backup and versioning.

It is not a replacement for the operational system. It is a publication format and presentation boundary.

## Stable Endpoint, Stable Data

Using a folio for ARK serving separates concerns:

- **Producer systems** fetch, normalize, enrich, and rebuild data.
- **Folio archives** package a stable snapshot of the rows and observed metadata.
- **ARK servers** resolve identifiers and render representations from that snapshot.

This makes deployments safer. A database migration, queue outage, Meilisearch rebuild, admin UI refactor, or source pipeline change does not have to affect ARK resolution.

For long-lived public identifiers, that separation matters.

## Suggested Resolver Flow

1. Open the configured folio file.
2. Look up the ARK in `item.dto_data`, `item.extras`, or a generated ARK lookup view.
3. Read `schema_table` and `schema_property` to understand the row's observed fields.
4. Render the row using the requested locale.
5. Return the requested representation.

For performance, a publication step may create a dedicated lookup view or index:

```sql
create view ark_lookup as
select
  local_id,
  label,
  dto_type,
  json_extract(dto_data, '$.ark') as ark,
  dto_data,
  extras
from item
where json_extract(dto_data, '$.ark') is not null;
```

If ARKs are stored in multiple fields, the publication step can normalize them into a dedicated lookup table. That table is derived data and can be rebuilt from the folio rows.

## Relationship to Folio Metadata

Folio archive metadata helps the ARK server explain what it is serving:

- `schema_table` identifies the row group and DTO type.
- `schema_property` describes observed fields and field stats.
- `term_set` and `term` describe controlled values when present.
- `docs` provides human/agent-readable context, provenance, and query examples.

This means an ARK endpoint can serve more than a record. It can also serve useful context about the archive snapshot that record came from.

## Why This Is a Reason to Use Folios

Folios are not just a convenient SQLite export. They are a stable publication boundary.

For ARKs, that boundary is especially valuable: the identifier endpoint and the endpoint data can be versioned, copied, backed up, audited, and redeployed independently from the rest of the system. The main application remains free to evolve, while public identifiers keep resolving from a compact, inspectable, self-describing archive file.
