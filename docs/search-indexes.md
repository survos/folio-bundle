# Shared search indexes (2026-10-13)

Every row of every published source folio is indexed **once**, in one of two Elasticsearch
indexes shared by every app. There is no index per folio, per set, per app or per run: a folio,
a curated set or a site is a `folioCode` filter on the same documents.

| index | one document per | cores (default) |
|---|---|---|
| `folio_item` | catalog row: object, archival record, newspaper issue, interview, collection, person | every core not listed as text |
| `folio_text` | text unit, pointing at its parent row | `article` (`survos_folio.search.text_cores`) |
| `folio_meta` | folio: build, row counts per core, declared facets, `complete` | — |

The split is by **search unit**, not by provider or DTO type. Objects, documents, paintings and
guns share almost every field and a search across them is one query with one set of facets, so
they share `folio_item`; `dtoType` and `role` tell them apart. Articles are a different unit —
a million per newspaper — and mixing them in would skew facet counts and scoring, so they get
`folio_text`.

Harvest is the only writer. Readers get a read-only key on `folio_*`.

## The document

One strict mapping (`FolioSearchIndex::mapping()`), shared by both families; the text-only
fields are absent on items. Built by `FolioSearchProjector` from the folio's SQLite file.

| field | type | from |
|---|---|---|
| `rowId` (also the ES `_id`) | keyword | `item.id`, `folioCode:coreCode:localId` |
| `folioCode`, `provider`, `dataset`, `coreCode`, `localId`, `dtoType` | keyword | the row |
| `role` | keyword | `record`, `agent` (per/org), `collection` (coll/series), `text` |
| `build` | keyword | the published file's stamp (see Coverage) |
| `label` (+ `label.sort`), `description` | text (+ keyword) | the row |
| `body` | text, **not in `_source`** | the words FTS would hold (`FolioFtsIndexer::searchBody()`) |
| `year` | integer | `dto.year`, else from the date |
| `dateStart`, `dateEnd` | date | `dto.date` as an inclusive interval: `1953` → whole year, `1941-12` → month, `1940-1945` → both |
| `hasImage`, `pageCount`, `mediaKinds` | boolean, integer, keyword | the `page` table (photo, scan, audio, video) |
| `license` | keyword | `RightsClassifier` over `license`, `rightsUri`, `rights`: CC0, CC BY…, Public domain, No known restrictions, In copyright, Not evaluated, Other |
| `links` | keyword | row ids this row links to: its series, collection, issue, creator |
| `attrs` | **flattened** | the folio's facets, exactly the values of its SQLite `item_facet` table |
| `parentRowId`, `issueId`, `articleTier`, `recordKind`, `pageSeq` | keyword / integer | text family only, from `extras` |

`attrs` is why one strict mapping can serve every dataset. Each folio declares its facets in
`schema_property` (`facet`/`filterable`); `FolioFtsIndexer::rowFacetValues()` computes the values
— term-set fields as codes, arrays as several values — and they land under `attrs.<field>`
without a mapping change. A `flattened` value is a keyword: terms aggregations and term filters
work, numeric ranges do not, so a field that needs a range gets a typed field of its own (`year`,
`dateStart`/`dateEnd`). `folio_meta.facets.fields` lists, per core and dtoType, which `attrs` keys
a reader should aggregate, so no reader opens SQLite to draw a sidebar.

**Translations are not indexed.** A `<dataset>.<locale>.folio` carries its source's row ids;
indexing it would overwrite the source documents. The listener skips them and `index()` refuses
them. Translated text belongs on the source document (`label.<locale>`), a schema change still
to make.

## Facets in ES

Facets are **aggregations**:

```json
{"size": 0,
 "query": {"bool": {"filter": [{"term": {"folioCode": "mus/cleveland"}}, {"term": {"role": "record"}}]}},
 "aggs": {"dtoType": {"terms": {"field": "dtoType"}},
          "culture": {"terms": {"field": "attrs.cul"}},
          "century": {"histogram": {"field": "year", "interval": 100}}}}
```

Picking a type adds `{"term": {"dtoType": "painting"}}` and the aggregations that type declares.
A set or a site is `{"terms": {"folioCode": [...]}}` over the same index; aggregate the common
fields plus the `attrs` keys every member declares. A text search hits `folio_item,folio_text`
together and collapses text hits on `parentRowId`; facet counts come from the item side, so they
count records, not pages.

## Coverage: when a folio counts as searchable

`build` is a stamp of the published file (`sha256(folioCode, size, mtime)`, 20 hex), so a reader
or an audit can tell from the file alone whether ES is current. Indexing a folio:

1. bulk-writes every row under the stamp — ids are row ids, so a rebuild overwrites in place;
2. deletes the folio's documents on any other stamp — the rows that disappeared;
3. writes `folio_meta` with `complete: false`, then **verifies**: for each family, the count of
   `folioCode = X AND build = stamp` must equal SQLite's row count, and nothing may remain on an
   older stamp;
4. only then sets `complete: true`. A folio that does not verify fails its message, so Messenger
   retries it and it ends up in the failure transport rather than silently half-indexed.

`folio:elastic:index --verify [--all | codes…]` reports `ok`, `missing`, `partial`, `stale`,
`outdated` (every row is there, written by an older `FolioSearchProjector::DOCUMENT_VERSION` — bump
it whenever a document's content changes), `unreachable` or `no-folio` per folio and exits 1 on any gap. `--stale-only` reindexes only what
does not verify; `--queue` hands each to the worker instead.

## Producing

- `survos_folio.elastic_auto_index: true` in Harvest only. `FolioPublishedEvent` (source locale)
  queues `IndexFolioRowsMessage`; `IndexFolioRowsHandler` indexes and verifies.
- Rows are merge-joined with their pages and links in row-id order, never held in memory:
  `news/rappnews4909` (967,642 rows) indexes at 110 MB peak.
- One writer host: runs are serialized per folio by a local file lock.
- Sizing (`survos_folio.search.shards`, `replicas`) applies when an index is created. A single
  node wants 0 replicas.

## Readers

Today `FolioRowSearch` uses the indexes only as its text matcher for a folio without `item_fts`
(search-policy.md). Next: a search whose hits, counts and facets come from ES for folios over
`live_facet_max_rows`, then for sets and sites — which retires the pooled set indexes
(`folio:elastic:build-set`, Fotostory's photo sets, Ink's articles) that existed only because
Meilisearch could not shard.

## Rehearsal (local, 2026-10-13)

| folio | rows | family | time | facet values vs SQLite |
|---|---|---|---|---|
| mus/cleveland | 83,553 | item | 5.6 s | 11,561 equal |
| nara/coll_bho-whpo (no FTS) | 135,777 | item | 8.8 s | 12 equal |
| news/rappnews4909 | 967,642 | 3,115 item + 964,527 text | 114 s | 4,097 equal |
| loc/pearl-harbor-interviews | 116 | item | 0.1 s | 214 equal |

Row counts equal SQLite on every folio; `folio_text` for rappnews4909 is 1.1 GB.

## Schema changes

The mapping carries `_meta.folioSearchVersion`. The writer refuses an index on another version
rather than mixing shapes: create the new version under new names (`search.item_index` …),
reindex, verify, switch readers, then drop the old indexes.
