# DuckDB as a folio reader

A DuckDB file is a rebuildable manifestation of a folio. The shared reader opens
local, HTTPS or S3 snapshots read-only. Existing Elasticsearch searches and
SQLite folio routes are unchanged. Vector indexing/querying is not implemented
by these routes; the reader boundary can support it later.

Enable in any reader application with `survos_folio.duckdb_browse: true`.
The default locator uses `DataPaths::folioFile(code, 'duckdb')`; trusted remote
URIs can be configured under `survos_folio.duckdb_sources`, keyed by folio code.
Alternatively alias `DuckDbFolioLocatorInterface` to the application's registry
adapter. Harvest does this for its registered `duckdb` artifacts. No dataset
registry or Harvest class is required in FotoStory or Ink.

With the default `/folio` route prefix:

- `/folio/mus/cleveland/duckdb/search` (`survos_folio_duckdb_search`)
- `/folio/mus/cleveland/duckdb/obj/74228` (`survos_folio_duckdb_row_show`)

The search route uses search-bundle's `duckdb://folio` adapter and the existing
folio search layout. The item reader maps item records to detached Folio/Core/Row
presentation objects, hydrates the declared DTO, and reuses the folio item
header/template. Its read-only body shows source links, images and stored JSON;
SQLite-only actions, claims/relations UI and document tools are not exposed here.
The route's `_folio_reader` default prevents SQLite initialization. Navigation
stays on this reader. Host firewall rules and folio-site restrictions still apply.

JSON-viewer JS and styles ship as a bundle Stimulus controller. Flex registers
`json-viewer` and `@andypf/json-viewer`; existing monolink consumers should update
their controller/importmap registration when adopting the new assets.

S3 uses `DuckDbFolioReader`/httpfs. Install httpfs in the runtime image; configure
credentials through DuckDB's credential configuration or provide a custom
connection service using `DuckDbS3Configuration`. These routes never accept
filesystem paths, remote URIs, credentials, or SQL from browser query parameters.
DuckDB 2.x and native PDO DuckDB are required only when this reader is used.

## JSON at the PHP boundary

The native PDO driver decodes SQL `JSON` into PHP arrays, while the folio export's
`VARCHAR` payloads arrive as JSON text. The item view accepts both and rejects
scalar normalized payloads. Text decoding preserves oversized integers as strings,
matching the driver's policy. DTO hydration uses the existing associative-array
contract; it is not a lossless representation of arbitrary JSON.

The search and detail JSON viewers receive an explicit `VARCHAR` projection of the
stored payload. This preserves `{}` versus `[]`, numeric object keys and nested
empty objects without decoding and re-encoding in PHP. The source folio and DuckDB
payload are not modified.

DuckDB 2.0's columnar `VARIANT` storage is a possible future improvement for
semi-structured payloads; adopting it needs separate export/query benchmarks.
Its asynchronous I/O also benefits remote native DuckDB storage. Neither feature
changes the PDO JSON-to-PHP conversion policy.
