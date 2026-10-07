# WallCard collections

`GET /api/{folioCode}/rows` is the lean JSON-LD/Hydra collection for museum clients.
`folioCode` accepts a provider/dataset code, a registered public slug, and a content
locale suffix. The shared FolioRouteAttributeListener (request priority 16) resolves
that value and switches SQLite before API Platform reads. The provider uses the
resolved request attribute, including the selected locale, rather than rebuilding
provider/dataset from API URI variables.

Parameters compose: `core` (default `obj`), `type`, `year[gte]`, `year[lte]`, exact
`city`, `state`, `country`, `donor`, `tag`, FTS5 `q`, `bbox=minLon,minLat,maxLon,maxLat`,
`ids=a,b,c`, `hasSize=1`, `hasImage=1`, `order=year|label|random`, `seed`, `page`, and
`itemsPerPage` (default 50; allowed 1–200). IDs take precedence over ordering and keep
the requested order across pages; repeated IDs are deduplicated. Unknown IDs are
omitted. Random order hashes local ID and seed, so it is repeatable across requests.
FTS input is literal words joined with AND, not executable FTS query syntax.

The collection contains `folio`, `hydra:totalItems`, `hydra:member`, and `hydra:view`.
Follow `hydra:next`; it preserves all filters and the original slug/locale URL.
Cards expose only label, year, geo, size, imagery, empty audio, source URL and rights.
The full item operation remains `/api/{folioCode}/rows/{localId}?core=obj` with its
existing detailed representation. The internal photo grid uses `/rows/grid`; the
old `/api/folios/{provider}/{dataset}/{coreCode}/rows` shape is removed.

## Size and source labels

Physical size is `{widthMm, heightMm, depthMm, kind, wPx, hPx}`. The dimensions bundle
parses museum H × W × D explicitly and returns integer millimetres. Labeled segments
prefer Frame/Framed, then Stretcher, Sight, Image, Sheet, then other valid segments.
Parenthetical inch equivalents are discarded. Pixel-only records keep `wPx/hPx`
with null physical dimensions; no DPI or real-world size is invented. Unparseable
physical measurements without pixel dimensions return null. `hasSize` applies this
same rule before pagination.

NPG splits the first creator from subsequent sitters. Cleveland preserves its
preformatted tombstone. Fortepan US preserves sourceCaption, donor and place.
Absent optional source metadata stays null, including folio-level license/credit
when those properties are absent; per-card rights remain available. A missing year
is not reconstructed from a sitter's dates or accession. FTS only searches fields
that the folio build indexed (current fpus does not index sourceCaption).

## Images

Only canonical page imagery is used. `image.thumb` uses the existing signed
`tiny` preset (fit 200, WebP); `image.medium` uses `thumb` (fit 400, WebP).
Both preserve aspect ratio and reuse the existing imgproxy cache. `image.full`
remains the source URL. Unity decodes WebP itself; no new server presets or JPEG
format overrides are needed. The configured presets and signing credentials are
required; misconfiguration fails explicitly instead of returning null derivatives.

Public catalogue responses allow cross-origin GET/HEAD/OPTIONS, expose ETag, use a
five-minute public cache lifetime, and honor If-None-Match. ETags hash the actual
representation, so rebuilt data invalidates them. Facets are a future endpoint.
