# Plan: projecting folio artifacts into record stores (Grist / Quickbase)

Status: **proposed**. Written 2026-08-26, **reconciled the same day against
`survos/grist-bundle`**, which landed independently while this was being drafted
(`d438b3eb`..`28dc44c1`) and already implements a large part of what the first
draft proposed building. Sections below marked *(covered)* describe work that no
longer needs doing.

## Why

A `.folio` artifact keeps its data in JSON blobs on a fixed `item` table
(`dto_data`, `extras`, `raw`), with `schema_table` / `schema_property` *describing*
what is inside them. The description is inferred, so nothing guarantees the data
agrees with it — which is why inspecting folio data needs a bespoke debugger, and
why that debugger keeps being unsatisfying.

Grist inverts this: its metadata is *prescriptive*, and the engine issues real DDL
so the physical schema cannot disagree. Projecting a folio into a record store
turns every "what is actually in this folio?" question into a `SELECT`, a sort, or
a facet — for free, in a UI that already exists.

The projection is a **read-only, disposable view**. The `.folio` stays
authoritative. Nothing here proposes making a record store a system of record.

## What is already proven

A Python spike (`docs/spike/folio2grist.py`) ran end to end against real
artifacts. Measured, not estimated:

| artifact | rows | tables | links | wall time |
|---|---|---|---|---|
| `mus/fortepan.folio` | 999 | 1 | – | ~12 s |
| `dc/0p096w19r.folio` (postcards) | 21,069 | 3 | 13k | 12.8 s |
| `dc/ht24xg10q.folio` (BPL anti-slavery) | 14,775 | 3 | 23.8k | 9.6 s |
| `curatescape/scioto.folio` | 173 | 4 | 72 | <2 s |

Spot-checks that mattered: `Per` sorted by created-count put William Lloyd
Garrison first with 4,574 items, which is historically correct for that
collection; scioto's Story→Stop links round-tripped into a working reverse
lookup. 130 MB of `.folio` produced 77 MB of `.grist` (the delta is FTS,
`item_facet`, terms, and the un-projected `raw`/`extras`).

**The key structural finding:** tables must be per **core**, not per `dto_type`,
because `link` is core-to-core (`left_core`/`right_core`). A photograph and a
document both live in `obj` and are told apart by a `dtoType` column. That is
also exactly what folio's own `row_<core>` schema tables already model.

## The landscape after grist-bundle

Three bundles now, layered:

| | owns |
|---|---|
| `record-store-bundle` | portable contracts, `FieldType`, Grist + Quickbase adapters (read + upsert) |
| `grist-bundle` | Grist specifics: schema, SQL, forms, webhooks, attachments — as services *and* MCP tools |
| `quickbase-bundle` | Quickbase adapter, QBL |

`grist-bundle`'s stated purpose is that a curation surface should be **declared
and reconciled** rather than clicked together. That is a different mode from what
this plan needs, and the difference is the main open question — see
"Declared vs generated" below.

### Already covered by grist-bundle *(delete from scope)*

The first draft proposed building all of these. They exist:

- **listing / describing tables and columns** — `GristSchemaManager::tables()`,
  `describeTable()`
- **adding columns** — `GristSchemaManager::addColumns()`. Passes an arbitrary
  `fields` array straight through, so `Choice`, `ChoiceList`, `Ref:Other` and
  `widgetOptions` all work without the projection needing an escape hatch.
- **read-only SQL** — `GristQueryRunner`, parameterized
- **forms** — `GristFormManager`, including the `layoutSpec` + `shareOptions`
  publish sequence. Worth noting because that was the single most expensive thing
  to work out from the API alone: a form section created without a `layoutSpec`
  renders every field and **cannot be submitted**, since the Submit button is a
  node inside that spec.
- **attachments** — `GristAttachmentManager`, including moving the byte store to
  object storage
- **webhooks** — `GristWebhookManager`

So the old "what stays provider-specific — use `GristClientInterface` as the
escape hatch" section is obsolete. There is now a real bundle there instead of a
raw client, and the projection should call it.

### Deliberately *not* covered, and correctly so

`addColumns()` is **additive only — it never drops or retypes**. The first draft
proposed an `alterField()`; that trap is better read as *justification for
grist-bundle's policy* than as a method to write:

> Grist's column-type `PATCH` does **not** convert existing data. It redeclares
> the column and leaves the previous value as a Python-`marshal` blob
> (`b'u\x03\x00\x00\x00120'` decodes to `'120'`). The REST API keeps reporting a
> clean value, so it is invisible until something reads the SQLite file directly.

A projection rebuilds into a fresh document, so it never needs to retype anything.
Keep it that way.

## What is still missing

### 1. Cardinality is lost in both adapters (unchanged, still live)

`grist-bundle` sits above this and does not fix it:

```php
// GristAdapter
'Choice', 'ChoiceList' => FieldType::Choice,
str_starts_with($nativeType, 'Ref:') || 'RefList:' => FieldType::Reference,

// QuickbaseAdapter
'text', 'text-multiple-choice', 'multitext' => FieldType::Text,
```

A portable read→write round-trip degrades a multi-select to a scalar with no way
to detect it. Not hypothetical for folio: `?array` + `facet` is genuinely
multi-valued (fortepan's `subjects` had 37 distinct values), and `link` is
many-to-many, i.e. `RefList:`.

**Fix:** `public bool $multiple = false` on `FieldSchema`, not new enum cases.
`Choice + multiple` covers Grist `ChoiceList` and Quickbase
`text-multiple-choice`; `Reference + multiple` covers `RefList:` and Quickbase
multi-reference.

### 2. Nothing creates a document or a table

`grist-bundle` adds columns to a table that already exists, in an application
already declared in YAML. Nothing in any of the three bundles does
`CreateTable`, and nothing creates a document. A projection needs both, per
folio, at runtime.

This is the largest remaining gap and it belongs in `grist-bundle` next to
`addColumns()`, not in the portable layer — see "Declared vs generated".

### 3. Batching is still unaddressed

`UpsertRecordsTool` iterates records and hands them over; `RecordWriterInterface`
does the same. Grist returns **413 well below 500 rows** once rows carry anything
large — hit on the first real run, with `denseSummary` present. Row *count* is the
wrong unit; the practical ceiling is near 900 KB per request.

This will bite whoever first pushes real folio rows through the MCP tools, not
just the projection.

### 4. Links have no home

`Ref:Other` is a documented column type in `AddColumnsTool`, but nothing
populates a `RefList` or derives the reverse. Both are needed; see Mapping.

## Declared vs generated — the real design question

`grist-bundle` resolves an application by **name**, from
`survos_record_store.applications`, with its tables enumerated in YAML.
`GristApplicationLocator` is built on that assumption throughout.

A folio projection cannot work that way. There are 2,096 folios; their tables are
discovered from `schema_table` at runtime, and the document does not exist until
the projection creates it. Writing a YAML block per folio is not viable.

Three ways out, in preference order:

1. **A dynamic application reference.** Let `GristApplicationLocator` accept a
   constructed reference (connection + document id) rather than only a
   config key. Smallest change; keeps every existing tool working unchanged and
   makes them usable against generated documents.
2. **The projection registers its document** in the record-store registry at
   runtime, so the rest of grist-bundle sees a normal application.
3. **The projection bypasses grist-bundle** and drives the client directly.
   Fastest to write, and wrong — it would duplicate `addColumns()` and the
   attachment/SQL work within a month.

Option 1 is the one to argue for, and it is worth raising as a grist-bundle issue
independently of folio: any generated-document use case hits the same wall.

## Mapping

### Property → field type

folio's PHP type vocabulary is small and closed, which is what makes this
tractable:

| `schema_property.type` | flags | `FieldType` | Grist |
|---|---|---|---|
| `?string`, `string` | `facet`, distinct ≤ 200 | `Choice` | `Choice` |
| `?string`, `string` | otherwise | `Text` | `Text` |
| `?int` | | `Integer` | `Int` |
| `?float` | | `Decimal` | `Numeric` |
| `?bool` | | `Boolean` | `Bool` |
| `?array` | `facet`, distinct ≤ 500 | `Choice` + `multiple` | `ChoiceList` |
| `?array` | otherwise | `Text` (JSON-encoded) | `Text` |

The cardinality probe is a `count(distinct …)` over `json_extract` (and
`json_each` for arrays) — cheap, and it is what keeps a 20k-row `city` column
from becoming a useless 5,000-option dropdown.

`schema_property` also carries `label`, `description`, and
`visible`/`searchable`/`filterable`/`sortable`, which map onto column labels,
descriptions and visibility directly. **folio already knows more about its own
shape than the target needs** — the projection is a translation, not a guess.

### Links → references

Each `link_type` becomes a `Reference + multiple` column on the left table
(`RefList:Other` in Grist). `link_type.reverse_code` already names the inverse,
so the reverse is free on Grist as a formula column:

```
Left.lookupRecords(ColName=CONTAINS($id))
```

That formula is **Grist-only**; Quickbase needs a real reverse relationship or a
report. Since `addColumns()` passes `fields` through verbatim, a formula column
needs no new API — just `isFormula` and `formula` in the spec.

### Identity

Keep folio's natural keys as ordinary columns — `FolioId` (`item.id`) and
`LocalId` (`item.local_id`). Never hash or synthesise a row identity; the record
store's own integer row ids are an implementation detail of the projection and
must not leak back into folio. `LocalId` is also the natural upsert key for
`grist_upsert_records`.

### Images

**Grist will not render an image from a URL in a cell** — its Markdown widget
passes `![](url)` through as literal text. Two options, and for folio the second
is right because the images are already hosted and `page.url` is the source:

- an `Attachments` column with uploaded bytes (`GristAttachmentManager`)
- a URL column plus the Image viewer custom widget, cursor-linked to the grid

Wiring that widget is undocumented and easy to get wrong: the column mapping
lives *inside* `customDef`, and the value is a **scalar** colRef, not a list,
unless the widget declares `allowMultiple`. The mapping key comes from the
widget's own `grist.ready({columns})` source, not from the widget manifest.

## Build hygiene (adjacent, partly fixed)

- **`.folio` sidecars** — [survos/mono#50](https://github.com/survos/mono/issues/50).
  `FolioService::finalize()` now ships finished artifacts in `journal_mode=DELETE`
  (`19908651`), which is what makes a `.folio` a single self-contained file and
  safe on read-only media.
- **zm should open folios read-only** —
  [survos/mono#51](https://github.com/survos/mono/issues/51). Under FrankenPHP
  workers the connection outlives the request, pinning the file open. Read-only
  alone is not enough: measured with a handle held open, a WAL folio still
  creates sidecars and a DELETE folio does not. Both halves are needed.
- **Readers**: `mode=ro` for anything long-lived; `immutable=1` only for one-shot
  CLI probes, since it promises the file never changes. Note it also *misreports*
  `journal_mode` as `delete` regardless of the header — verify via header bytes
  18/19 (2 = WAL).

## Phases

1. `FieldSchema.multiple` + adapter type-map corrections, both providers. Fixes a
   live defect independent of everything else.
2. Byte-aware chunking on the write path, in `record-store-bundle`'s adapters so
   both the tools and the projection inherit it.
3. Dynamic application references in `grist-bundle` (option 1 above), plus
   `createDocument` / `createTable` alongside `addColumns()`.
4. `FolioRecordStoreService` in folio-bundle: cores → tables, properties →
   fields, rows → records, calling grist-bundle rather than the raw client. Port
   the spike.
5. Links → `RefList` + the reverse formula.
6. Quickbase: run phase 4 against it unchanged. The real test of the abstraction —
   and the point at which a portable schema-write contract earns its place. Not
   before.

## Open decisions

- **Reverse direction (record store → folio) is not designed and not built.** It
  forces the question of what is authoritative, since a `.folio` is a build
  artifact a pipeline regenerates. Unnecessary for the debugging use case; it is
  the entire point for PGSC-style curation — which is now also grist-bundle's
  stated use case, so this should be decided with that bundle rather than for
  folio alone.
- **Whether the portable layer ever needs a schema-write contract.** The first
  draft assumed yes. With grist-bundle owning Grist schema work, the honest
  answer is: not until Quickbase needs the same thing. Deferring it costs
  nothing and avoids designing an abstraction from one implementation.
- **Fixture distribution.** Six small artifacts sit in `data/folio/` for
  development, deliberately **not committed** (folio-bundle ships via Packagist;
  9.3 MB total), excluded via `.git/info/exclude`. Fetch-on-demand preferred, and
  being designed separately.
- **Scale ceiling.** Per-doc SQLite plus a per-doc Python engine makes Grist
  excellent for many small independent projections and wrong for one large
  corpus. `wikibase/enslaved.folio` (2.4 GB) is out of scope; the 12k–21k row
  artifacts tested here are comfortable.
