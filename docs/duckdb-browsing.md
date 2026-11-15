# DuckDB as a folio reader

A DuckDB file is a rebuildable manifestation of a folio. The shared reader opens
local, HTTPS or S3 snapshots read-only. Existing Elasticsearch searches and
SQLite folio routes are unchanged. Vector indexing/querying is not implemented
by these routes; the reader boundary can support it later.

Enable in any reader application with `survos_folio.dfolio_browse: true`.
The default locator uses `DataPaths::folioFile(code, 'duckdb')`; trusted remote
URIs can be configured under `survos_folio.dfolio_sources`, keyed by folio code.
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

## File naming

New DuckDB Folios use `.dfolio`; SQLite retains `.folio` and `.folio.gz`.
Existing `.duckdb` artifact URIs remain supported. Local discovery prefers
`.dfolio`, falling back to `.duckdb` until a rebuild publishes the new name.
Remote readers accept either extension and use the exact registered URI.
Rebuilds update the artifact URI; no bulk rename is needed.


## Replacing published files at stable S3 keys

Harvest publishes a dataset at stable `.dfolio`, `.drend` and `.dwords` addresses.
The catalog's opaque artifact `revision` changes after successful publication;
no revision is required in the object name. The consumer must enable the dataset
API/change-feed synchronization (or configure `folio_server` for catalog refresh).

`FolioCatalogClient` retains `remoteUri` in each entry. On successful refresh or
sync, and when each worker observes the updated catalog, it emits
`FolioPublicationChangedEvent` once per changed dataset, including withdrawals.
The event contains previous/current variants and matches companion rendition and
word-index addresses as well. Revision equality is used, not timestamps ordered
as versions. A changed address is also an invalidation even if a revision repeats.

Before each main HTTP request, `FolioPublicationRefreshListener` reloads the shared
catalog cache and compares it with that worker's last observations. This is
necessary because closing connections in a scheduler process cannot close handles
in a FrankenPHP worker. The listener runs before routing and controller caches.
Unchanged datasets keep their pooled connections. The bundle releases affected
Folio/rendition pool entries (and the non-pooled rendition fallback), and clears
related publisher-receipt and reader-URL caches. Bookkeeping uses weak references,
so it does not itself keep evicted native connections alive.

A sync process and HTTP workers must share `var/catalog/folio-catalog.json` for
next-request visibility after synchronization. Separate replicas without that
shared file discover changes through their own sync/catalog refresh interval.
This is a pull protocol, not an immediate callback from Harvest to every reader.
Long-running non-HTTP consumers must reset/read the catalog between work items.

Application-specific derived caches and services retaining their own PDO handles
must subscribe to `FolioPublicationChangedEvent` too: delete only keys for its
`datasetKey`, and drop their references to affected handles. Do not clear all of
`cache.app`. Use the event's entries inside a listener instead of recursively
fetching the catalog. Neither PHP nor the pool can forcibly close a PDO still
referenced by application code. Folio schema caches are schema-fingerprint keyed
and do not need content-publication invalidation.

Stable keys do not make a multi-object upload atomic. This invalidation makes the
next request after notification open new files; it does not cancel in-flight
requests or prevent reads during the upload window. For a strict no-mixed-build
guarantee, quiesce readers around replacement. Rendition build checks still reject
mismatched pairs. A full sync that fails its apply callback does not publish a new
catalog or issue a successful-publication notification.
