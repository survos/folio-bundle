# Per-folio search policy (scaffold)

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

This is configuration scaffolding only. No builder or reader consumes it yet:
FTS5 generation, validation and search routing remain unchanged. Tobacco has not
opted in. `allowFtsSkip` grants permission for a future builder; it does not assert
that a usable Elasticsearch index exists.

Before activating this policy:

- Carry the policy into the published folio so readers know its search requirements.
- Route search to the matching Elasticsearch index and verify readiness before
  omitting FTS5; explicitly report unavailable search rather than returning unfiltered rows.
- Separate browse/facet index construction from FTS5 generation.
- Replace the `item_fts` existence check used as a build-completion marker in
  FolioService and validation with an explicit, backend-independent completion check.
- Cover normal builds, rebuilds, archive inflation, CLI FTS rebuilds, search,
  retrieval/chat and legacy folios. An intentional omission must be distinguishable
  from an incomplete or damaged build.

The existing `extras.ftsContent: none` option is independent: it builds a
contentless FTS5 index and still provides SQLite full-text search.
