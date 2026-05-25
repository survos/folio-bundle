# Folio States: Archive and Working

A folio is a SQLite file with a defined set of tables. It exists in one of two states.

## Archive

The distribution format. Optimized for size, portability, and direct readability.

- **No secondary indexes.** PRIMARY KEY and UNIQUE constraints remain (SQLite enforces these structurally). No `CREATE INDEX` results.
- **No FTS5 tables.** No `item_fts`, no `item_vocab`.
- **No generated views.** The `dto_*` convenience views are derived data, rebuilt on inflate from `schema_table`/`schema_property`.
- **Self-describing.** SQLite's own `sqlite_master` contains commented DDL for every table. The `schema_table`/`schema_property` tables describe observed data. The `docs` table contains human-readable documentation. A consumer with nothing but `sqlite3` can understand the file.
- **VACUUMed.** No wasted pages.
- **Optionally gzipped** (`.folio.sqlite.gz`) for storage and transfer. Compression is a transport concern orthogonal to state.

An archive folio is directly queryable — queries just scan instead of seeking indexes. For a file that gets inflated before heavy use, this is the right trade-off.

## Working

The operational format. Everything in archive, plus:

- **Secondary indexes** on columns used for filtering, sorting, and joins.
- **FTS5 full-text index** (`item_fts`) with `fts5vocab` companion (`item_vocab`).
- **Generated views** (`dto_*`) flattening `json_extract()` calls into named columns.

## Transitions

```
Archive  ──inflate──>  Working
Working  ──deflate──>  Archive
```

**inflate** = create indexes + rebuild FTS5 + create views
**deflate** = drop indexes + drop FTS5/vocab + drop views + refresh metadata snapshot + VACUUM

Compression is outside these transitions:

```
.folio.sqlite.gz  ──gunzip──>  archive  ──inflate──>  working
working  ──deflate──>  archive  ──gzip──>  .folio.sqlite.gz
```

## Naming

| Term | Meaning |
|------|---------|
| **archive** | No indexes, no FTS, no views. Portable, small, self-describing. What you ship. |
| **working** | Indexes, FTS, views present. What you query and browse. |
| **compressed** | Gzipped. Orthogonal to archive/working. |

"Archive" and "working" are preferred over "deflated/inflated" or "indexed/unindexed." The first pair describes intent (ship vs query); the second pair is mechanism; the third is incomplete (working also adds FTS and views, not just indexes).

The code can still use `inflate()` and `deflate()` as method names for the transitions — those verbs are clear for the operation, even though the state names are archive/working.

---

## Schema Representation

### SQLite is already self-describing

SQLite stores the original `CREATE TABLE` SQL — including comments — in `sqlite_master.sql`. Combined with `PRAGMA table_info()`, `PRAGMA foreign_key_list()`, and `PRAGMA index_list()`, any language can fully introspect a folio without external files or custom metadata tables.

```sql
-- Get the commented DDL for any table
SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'item';

-- Get column details
PRAGMA table_info('item');

-- Get foreign keys
PRAGMA foreign_key_list('item');

-- Get indexes
PRAGMA index_list('item');
```

This means a well-commented `CREATE TABLE` statement is both the schema definition and its documentation. No custom `_table`/`_column` registry needed — SQLite already has one built in.

### Commented DDL as the schema contract

The folio schema is defined with inline comments in the `CREATE TABLE` statements. These comments are preserved verbatim in `sqlite_master.sql` and serve as the portable schema documentation:

```sql
CREATE TABLE IF NOT EXISTS item (
    -- Normalized row within a core
    id        TEXT PRIMARY KEY,    /* folioCode:coreCode:localId */
    core_id   TEXT NOT NULL REFERENCES core(id) ON DELETE CASCADE,
    local_id  TEXT NOT NULL,       /* original ID from source data */
    label     TEXT,                /* display label */
    dto_type  TEXT,                /* DTO type identifier */
    dto_data  TEXT NOT NULL DEFAULT '{}',  /* canonical DTO-shaped JSON */
    extras    TEXT NOT NULL DEFAULT '{}',  /* non-DTO source fields */
    raw       TEXT,                /* optional raw source copy */
    UNIQUE(core_id, local_id)
);
```

A Python developer, a report writer, or an AI agent can run `SELECT name, sql FROM sqlite_master WHERE type = 'table' ORDER BY name` and get a fully documented schema — no PHP, no Doctrine, no external spec file.

### Two layers of description

The folio carries two complementary layers of schema information:

| Layer | Source | Describes | Audience |
|-------|--------|-----------|----------|
| Structural | `sqlite_master` + PRAGMAs | Physical tables, columns, types, constraints, comments | Anyone building a toolkit or ORM mapping |
| Semantic | `schema_table`, `schema_property` | Observed DTO data fields, types, profiler stats, display/search metadata | Report writers, UI builders, AI agents |

The structural layer says "this folio has a table called `item` with these columns." The semantic layer says "the items in the `obj` core use DTO type `drawing` and have these observed fields with these statistics."

### Bootstrapping

Any Symfony application that installs `folio-bundle` and configures the `folio` entity manager can create the base schema via `doctrine:schema:update --force --em=folio`. During development this is the right approach — Doctrine entities are the authoritative source for the PHP ecosystem.

The commented DDL makes the format inspectable by anyone, but we are the creators and publishers of the folio schema. Other toolkits would consume existing folios and use SQLite introspection to understand the format, not bootstrap from scratch.

Schema versioning and migration tooling will come when there are actual schema versions to migrate between. No point engineering that now.

### Index registry: `_index`

One infrastructure table has no SQLite-native equivalent: the recommended indexes for the working state. Indexes that exist in a working folio are visible in `sqlite_master`, but once deflated they're gone — and the archive needs to remember what to recreate on inflate.

```sql
CREATE TABLE IF NOT EXISTS _index (
    -- Recommended indexes, created on inflate, dropped on deflate
    name       TEXT PRIMARY KEY,
    table_name TEXT NOT NULL,       /* which table */
    columns    TEXT NOT NULL,       /* comma-separated column names or expressions */
    is_unique  INTEGER NOT NULL DEFAULT 0,
    condition  TEXT,                /* partial index WHERE clause */
    created_by TEXT                 /* schema, fts, user, auto */
);
```

`inflate` reads `_index` and runs `CREATE INDEX IF NOT EXISTS` for each row. `deflate` reads `_index` and runs `DROP INDEX IF EXISTS`.

### Folio metadata: `_meta`

Key-value metadata about the folio itself.

```sql
CREATE TABLE IF NOT EXISTS _meta (
    -- Folio-level key-value metadata
    key   TEXT PRIMARY KEY,
    value TEXT
);
```

Expected keys:

| key | example value |
|-----|---------------|
| schema_version | 1 |
| folio_state | archive |
| created_at | 2026-05-25T14:30:00Z |
| created_by | folio-bundle 1.2.0 |

`folio_state` records whether the file is currently `archive` or `working`, so code can detect state without probing for indexes.

---

## Table Classification

### Infrastructure (prefixed with `_`)
- `_meta` — key-value folio metadata
- `_index` — index registry (inflate/deflate targets)

### Structural (folio skeleton)
- `folio` — one row, the root record
- `core` — named collections
- `relation_type` — relation definitions

### Data (user content)
- `item` — normalized rows
- `term_set` / `term` — controlled vocabularies
- `relation` — row-to-row links

### Metadata (self-description of data)
- `schema_table` — observed DTO table definitions
- `schema_property` — observed field definitions with display/search metadata
- `docs` — generated documentation

### Working-only (not in archive)
- `item_fts` — FTS5 full-text search index
- `item_vocab` — fts5vocab companion
- `dto_*` views — flattened convenience views
