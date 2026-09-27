# Per-folio search policy

Dataset metadata can declare a search policy in `dataset.extras.search`:

```json
{
  "backend": "elasticsearch",
  "allowFtsSkip": true
}
```

`FolioSearchConfiguration::fromExtras()` parses and validates this contract.
Omitted settings mean `backend: sqlite` and `allowFtsSkip: false`. Elasticsearch
alone does not grant permission to omit the local index. Permission is valid only
with Elasticsearch. Unknown settings and incorrect types are rejected.

## What consumes it (2026-09-25)

The **builder** does. `FolioIngestService` carries the policy into the published folio —
`allowFtsSkip` becomes `folio.fts_content = 'off'`, alongside the existing `stored` and
`none` — and `FolioFtsIndexer::rebuild()` reads it back:

| dataset | folio rows | result |
|---|---|---|
| no policy | any | indexed — no search at all is worse than a slow build |
| `allowFtsSkip` | ≤ `survos_folio.fts_max_rows` | indexed anyway; cheap, and the file stays self-contained |
| `allowFtsSkip` | over it (or limit 0) | **no `item_fts`**, and any index from an earlier build is dropped |

`fts_max_rows` defaults to 0, so an opt-out is honored as soon as a dataset declares one.
`folio:fts:rebuild --force` indexes a skipped folio anyway.

Two properties the skip path keeps, both load-bearing:

- **The old index is dropped, not left.** A rebuild reassigns item rowids, so an `item_fts`
  from the previous build points at the wrong rows — worse than no index.
- **Browse indexes, `sort_key` and the precomputed facet counts are still built.** They are
  cheap and readers depend on them; only FTS5 generation is skipped, which is the separation
  this document asked for.

Motivation, measured: `news/rappnews4909` is 966,590 page rows and 1.3 GB of OCR. Its index is
the largest table in a 6 GB folio and the longest phase of every rebuild — the phase that has to
fit inside a worker's memory limit.

## Where a skipped folio's text search goes (2026-09-27)

To one Elasticsearch index shared by every folio, `survos_folio.elastic_row_index` (default
`folio_row`, deliberately not app-prefixed: harvest writes it, zm/Ink/fotostory read it, like the
folio files themselves). One document per row, `_id` = the row's composite id, holding
`folioCode`, `coreCode`, `label` and `body` — the same text `FolioFtsIndexer::searchBody()` would
have put in `item_fts`, `english`-analysed as FTS is porter-stemmed.

- **Filling it.** A finished build with `fts_content = off` dispatches `IndexFolioRowsMessage`
  (route it to an async transport; unrouted it runs inline). By hand:
  `folio:elastic:index <folioCode>… [--queue] [--remove]`. A reindex writes under a new build stamp,
  then deletes the folio's older rows, so searches never see an empty folio mid-reindex.
- **Querying it.** `FolioRowSearch` gives the SQLite adapter a `textMatcher` when the folio has no
  `item_fts`. ES returns up to `elastic_match_limit` (1,000) row ids, best first, filtered by
  folio and core; SQLite treats them as the match (a `__fts` CTE of rowid + rank), so scope,
  filters, facets, sorting and hit columns are unchanged. Facets are always live here: the set is
  capped. Facet filters apply to those 1,000, not to ES, so a narrow filter over a broad query can
  miss rows past the cap, and the result count reads 1,000 for any broader query ("courthouse" is
  8,105 rows on rappnews4909).
- **When it can't answer** (node down, 5 s timeout, index missing, folio not indexed yet) the
  matcher returns null and the search falls back to `textFallbackColumns` (label LIKE): narrower,
  never unfiltered. A folio that is indexed and matches nothing returns no hits.

Measured on a no-FTS copy of `news/rappnews-digital` (584 articles): hit counts identical to FTS
on every probe query, same first page (one ranking swap), 6–24 ms per search. At scale,
`news/rappnews4909` (967,642 rows, 650 MB of text) indexed in 55 s at 155 MB peak memory; a query
then costs 47–100 ms in ES plus 13–119 ms for the SQLite page ("snap bean": 69 ids vs FTS's 70).

Still open:

- Replace the `item_fts` existence check used as a build-completion marker in FolioService and
  validation with an explicit, backend-independent completion check. `fts_content = 'off'` is the
  signal that distinguishes an intentional omission from a damaged build; nothing reads it yet.
- Cover archive inflation, retrieval/chat and legacy folios.

## The query-side limit is separate

`survos_folio.live_facet_max_rows` (default 500,000) gates *facet counts*, not the index: past it
a text query returns its hits with empty facet distributions, because those counts are aggregated
over the matching rows and cannot come from the precomputed table. On `news/rappnews4909` that
aggregation is ~5 s of a ~6 s first-seen query against ~1 s for hits and count. Filter-only
queries, which the precomputed per-core table does answer, keep their counts. Empty rather than
approximate: counts over the wrong row set read as the number of matches they are not.

The existing `extras.ftsContent: none` option is independent: it builds a
contentless FTS5 index and still provides SQLite full-text search.
