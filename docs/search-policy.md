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

Still open before a large folio can actually opt out:

- Route text search to the matching Elasticsearch index and verify readiness; explicitly report
  unavailable search rather than returning unfiltered rows. Until this lands, opting a folio out
  leaves its search box with nowhere to go.
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
