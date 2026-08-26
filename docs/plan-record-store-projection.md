# Plan: projecting folio artifacts into record stores (Grist / Quickbase)

Status: **proposed**, with a working spike. Written 2026-08-26.

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

## Where the code goes

The abstraction already exists — `survos/record-store-bundle` is
provider-neutral, with `survos/quickbase-bundle` supplying the Quickbase adapter.
So:

- **`record-store-bundle`** gains the schema-mutation contract and multi-valued
  field support. It learns nothing about folio.
- **`folio-bundle`** gets the projection command, depending on the *contracts*
  rather than on Grist. Consequence worth stating plainly: the same command can
  target Quickbase. The mapping logic — `schema_property` → `FieldType`,
  `link_type` → multi-reference, `reverse_code` → the inverse — is folio
  knowledge and provider-agnostic.

Per the one-class-per-feature convention, this is a `FolioRecordStoreService`
with `#[AsCommand]` methods, not a class per command.

## Required contract changes

These are cheap now and expensive once the adapters have more callers.

### 1. Cardinality is currently lost (this is a live defect)

Both adapters flatten multi-valued fields, in different places:

```php
// GristAdapter
'Choice', 'ChoiceList' => FieldType::Choice,
str_starts_with($nativeType, 'Ref:') || 'RefList:' => FieldType::Reference,

// QuickbaseAdapter
'text', 'text-multiple-choice', 'multitext' => FieldType::Text,
```

A portable read→write round-trip therefore degrades a multi-select into a scalar
with no way to detect it. This is not hypothetical for folio: `?array` + `facet`
is genuinely multi-valued (fortepan's `subjects` had 37 distinct values), and
`link` is many-to-many, i.e. `RefList:`.

**Fix:** add `public bool $multiple = false` to `FieldSchema` rather than
expanding the enum. `Choice + multiple` then covers Grist `ChoiceList` and
Quickbase `text-multiple-choice`; `Reference + multiple` covers `RefList:` and
Quickbase multi-reference. No new enum cases, and it composes.

### 2. Schema mutation

The README defers this deliberately, which was right for proving the read
contract — but a projection is *entirely* schema mutation. Add it as a separate
opt-in interface so adapters choose to implement it:

```php
interface SchemaWriterInterface
{
    public function createTable(ApplicationReference $app, TableSchema $table): TableReference;
    public function addFields(TableReference $table, FieldSchema ...$fields): void;
    public function alterField(TableReference $table, FieldSchema $field): void;
}
```

with `ProviderCapability::SchemaWrite` advertising it, matching the existing
capability design. Do **not** widen `RecordStoreAdapterInterface`.

**Trap to encode in `alterField`:** Grist's column-type `PATCH` does *not*
convert existing data. It redeclares the column and leaves the previous value as
a Python-`marshal` blob (`b'u\x03\x00\x00\x00120'` decodes to `'120'`). The REST
API keeps reporting a clean value, so this is invisible until something reads the
SQLite file directly. `alterField` should convert explicitly or refuse.

### 3. Batching must be byte-aware, inside the adapter

`upsert()` currently passes the whole record array to `addRecords`. Grist returns
**413 well below 500 rows** once rows carry anything large — this was hit on the
first real run with `denseSummary` present. Row *count* is the wrong unit.

Each adapter should carry its own payload budget and chunk internally; callers
should not have to know. Grist's practical ceiling sits near 900 KB per request.

## Mapping

### Property → field type

folio's PHP type vocabulary is small and closed, which is what makes this
tractable:

| `schema_property.type` | flags | `FieldType` |
|---|---|---|
| `?string`, `string` | `facet`, distinct ≤ 200 | `Choice` |
| `?string`, `string` | otherwise | `Text` |
| `?int` | | `Integer` |
| `?float` | | `Decimal` |
| `?bool` | | `Boolean` |
| `?array` | `facet`, distinct ≤ 500 | `Choice` + `multiple` |
| `?array` | otherwise | `Text` (JSON-encoded) |

The cardinality probe is a `count(distinct …)` over `json_extract` (and
`json_each` for arrays) — cheap, and it is what keeps a 20k-row `city` column
from becoming a useless 5,000-option dropdown.

`schema_property` also carries `label`, `description`, and
`visible`/`searchable`/`filterable`/`sortable`. Those map onto column labels,
descriptions, and visibility directly. **folio already knows more about its own
shape than the target needs** — the projection is a translation, not a guess.

### Links → references

Each `link_type` becomes a `Reference + multiple` column on the left table.
`link_type.reverse_code` already names the inverse, so the reverse direction is
free on Grist as a formula column:

```
Left.lookupRecords(ColName=CONTAINS($id))
```

That formula is **Grist-only**. Quickbase needs a real reverse relationship or a
report; see "stays provider-specific" below.

### Identity

Keep folio's natural keys as ordinary columns — `FolioId` (`item.id`) and
`LocalId` (`item.local_id`). Never hash or synthesise a row identity: the record
store's own integer row ids are an implementation detail of the projection and
must not leak back into folio.

## What stays provider-specific

Do not invent portable abstractions for these. They belong behind
`GristClientInterface` (which already exists as the escape hatch, and the
record-store README already has the right instinct here):

- formula columns, including the reverse-link lookup above
- custom widgets (Image viewer, Map, QR) and page layouts
- Grist's read-only `/sql` endpoint
- attachments-vs-URL semantics: **Grist will not render an image from a URL in a
  cell** (its Markdown widget passes `![](url)` through as literal text). Images
  are either an `Attachments` column with uploaded bytes, or a URL column plus
  the Image viewer widget. For folio the widget is correct — the images are
  already hosted, and `page.url` is the source.

## Build hygiene (independent of this plan, but adjacent)

Found while gathering fixtures, and worth fixing regardless of how artifacts are
distributed:

- **2,092 of 2,096 `.folio` files carry orphaned `-wal` / `-shm`.** Every WAL is
  0 bytes, so no data was lost. Tracked as
  [survos/mono#50](https://github.com/survos/mono/issues/50): `closeActive()`
  already does the right thing but isn't called after the last dataset, and
  read-only probes (`isInflated()`, `FolioValidateCommand`) reopen the file
  without `immutable=1` and recreate the sidecars.
- **Ship `journal_mode=DELETE`, not WAL.** A distributed `.folio` is a read-only
  artifact with no concurrent writers; WAL buys nothing and costs two sidecars.
  A lingering `-wal` on read-only media (S3-backed mount, read-only volume) can
  make SQLite attempt recovery and refuse to open.
- **Readers should open `file:x.folio?mode=ro&immutable=1`** — that leaves no
  sidecars behind at all.

## Phases

1. `FieldSchema.multiple` + adapter type-map corrections, both providers. Small,
   and it fixes a real defect independent of everything else.
2. `SchemaWriterInterface` + `ProviderCapability::SchemaWrite`, Grist adapter
   only. Byte-aware chunking in `upsert()`.
3. `FolioRecordStoreService` in folio-bundle: cores → tables, properties →
   fields, rows → records. Port the spike.
4. Links → multi-reference, plus the Grist-only reverse formula behind a
   capability check.
5. Quickbase adapter implements `SchemaWriterInterface`; run phase 3 against it
   unchanged. This is the real test of whether the abstraction holds.

## Open decisions

- **Reverse direction (record store → folio) is not designed and not built.** It
  forces the question of what is authoritative, since a `.folio` is a build
  artifact a pipeline regenerates. Unnecessary for the debugging use case; it is
  the entire point for PGSC-style curation. Decide which one is actually wanted
  before either bundle grows a writer.
- **Fixture distribution.** Six small artifacts were copied to `data/folio/` for
  development and are deliberately **not committed** (folio-bundle ships via
  Packagist; they total 9.3 MB). They are excluded via `.git/info/exclude`.
  A fetch-on-demand mechanism is preferred and is being designed separately.
- **Scale ceiling.** Per-doc SQLite plus a per-doc Python engine makes Grist
  excellent for many small independent projections and wrong for one large
  corpus. `wikibase/enslaved.folio` (2.4 GB) is out of scope; the 12k–21k row
  artifacts tested here are comfortable.
