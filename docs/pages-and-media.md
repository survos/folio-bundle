# Pages & the shared media identity

How a row's imagery is modelled, and how a folio `Page` talks to media-bundle
`Media` and mediary `Asset` without any cross-database relation.

## TL;DR

- **Every viewable image is a `Page`.** A single-image object is a row with one
  page (`seq=1, pageIndex=0`); a 200-page manuscript is a row with 200 pages.
  There is no "single image vs. multiple images" special case anywhere downstream
  — the viewer, the AI button, the thumbnail, IIIF generation all read `row.pages`.
- **`Page` is named after the IIIF canvas/page.** A mask having a "Page 1" is
  colloquially odd but correct: it's "canvas 1 of the mask." IIIF models audio and
  video as canvases too, so the name survives the future generalisation to A/V.
- **`Page` is self-contained.** It stores everything the folio SQLite needs on its
  own (`url`, `seq`, `pageIndex`, `type`, OCR `text`, `denseSummary`, dimensions).
  It does **not** depend on media-bundle or mediary at read time.
- **One id joins all three stores:** `Page.mediaId == Media.id == Asset.id ==
  MediaIdentity::idFromOriginalUrl(url)` (xxh3 of the canonical URL). Content-
  addressed, computed independently in each place, identical for the same URL.

## The three entities

| Store | Entity | PK | Owns |
|-------|--------|----|------|
| folio SQLite | `Survos\FolioBundle\Entity\Page` | `{rowId}#{seq}` | the IIIF page/canvas: `url`, `seq`, `pageIndex`, `type`, `text`, `denseSummary`, `ledger`, `layout`, `width`, `height` |
| app Postgres | `Survos\MediaBundle\Entity\BaseMedia` (Photo/Video/Audio) | `xxh3(url)` | thin app-side cache that syncs to mediary: `s3Url`, `smallUrl`, `aiCompleted` pointers |
| mediary Postgres | `App\Entity\Asset` | `xxh3(url)` | server-side store: archive, imgproxy `/info`, full AI pipeline, page derivatives (`parentKey`/`pageNumber`) |

They live in **three different databases**. They are joined by a shared
content-addressed key, never by a Doctrine association.

## The shared identity: `MediaIdentity`

`Survos\MediaBundle\Util\MediaIdentity::idFromOriginalUrl($url)` returns
`hash('xxh3', trim($url), false)` — a **16-char** lowercase hex string. mediary's
`Asset` already uses this (from `vendor/survos/media-bundle`); media-bundle's
`BaseMedia` uses it directly. folio must use **the same function** so that, for a
given image URL, all three compute the identical id.

### Action: promote `MediaIdentity` to `data-contracts`

`Asset`, `Media`, and `Page` all need the canonical id function, and all three
already depend on `survos/data-contracts`. Move `MediaIdentity` there as the single
source of truth, and have media-bundle re-export / alias it for BC. folio-bundle
then `composer require`s `data-contracts` (it already does) and computes `mediaId`
at build time.

### Action: standardise the id column width on 16

- mediary `Asset.id`: `length: 16` with a hard `strlen($value) === 16` guard. ✅
- media-bundle `BaseMedia.id`: `length: 32`. ⚠️ value is 16 chars; column is
  oversized and the comment claims 32. Shrink to 16.
- folio `Page.mediaId`: `length: 32`, nullable. ⚠️ shrink to 16.

The values already match (xxh3 is 16 hex everywhere); the mismatched widths/guards
are a latent footgun the moment code assumes 32. Standardise on 16 when promoting
the util.

## `Page.type` — the imagery-role discriminator

A new, **mutable** discriminator on `Page`, authored by the normalizer and
overridable later by AI or a human. It is the *medium / imagery-role* axis — what
the viewer renders and which AI path applies — **not** the content genre.

```php
enum PageType: string
{
    case Photo    = 'photo';     // a photograph OF a physical object/scene → visual AI
    case Scan     = 'scan';      // a flat digitisation of a page/document → OCR
    case Document = 'document';  // a born-digital page (PDF render) → OCR
    case Audio    = 'audio';     // future
    case Video    = 'video';     // future
    case Other    = 'other';
}
```

Keep the three axes straight — they do not collapse into each other:

| Axis | Field | Example | Lives on |
|------|-------|---------|----------|
| content genre | `ContentType` (data-contracts) | "this object is a **coin**" | the row (`dtoData`) |
| imagery role / medium | `Page.type` | "this asset is a **Photo**" | each `Page` |
| media medium (STI) | media-bundle `BaseMedia.type` | `photo \| video \| audio` | `Media` |

A coin row (`ContentType=coin`) → two `Page`s, both `type=Photo` (obverse/reverse).
A manuscript row (`ContentType=manuscript`) → N `Page`s, all `type=Scan`.

`Page.type` is a **superset** of media-bundle's medium enum: `Photo/Scan/Document`
all map to medium `image`; `Audio→audio`, `Video→video`. So the two stay
reconcilable while `Page.type` carries the finer OCR-vs-visual signal folio needs.

`ContentType` already exposes `needsOcr()` / `needsVisualAi()` at the row level;
`Page.type` lets a single row mix roles when its assets are heterogeneous.

## The `page.jsonl` contract (what every normalizer must emit)

`folio:build` populates `Page` **exclusively** from
`{datasetKey}/normalize/page.jsonl` (`FolioIngestService::ingestPages()`). There is
no synthesis from row-level image fields — if a row has no `page.jsonl` line, it
has zero pages, no AI button, no IIIF canvas. **So under model B every normalizer
emits `page.jsonl`, one line per viewable image, including single-image rows.**

One JSONL record per page:

| Key | Req | Meaning |
|-----|-----|---------|
| `coreCode` | ✅ | the row's core (e.g. `obj`) — joins to `Row::id("{datasetKey}:{coreCode}", localId)` |
| `localId` | ✅ | the parent row's local id |
| `url` | ✅ | the page image / source URL |
| `seq` | – | 1-based order within the row (defaults to line counter) |
| `pageIndex` | – | 0-based index within the source binary: `0` for a standalone jpg, the PDF page number otherwise |
| `type` | – | `PageType` value (`photo`/`scan`/…); normalizer's best guess, AI/human may revise |
| `mediaId` | – | `MediaIdentity::idFromOriginalUrl(url)` — **the join key**; if omitted, build computes it |
| `text` | – | OCR text (usually folded in later from the media sidecar) |
| `denseSummary` | – | ≤350-char summary (later) |
| `ledger` | – | structured ledger extraction (later) |
| `layout` | – | per-page layout blocks (later) |
| `width` / `height` | – | pixel dimensions |

Example:

```json
{"coreCode":"obj","localId":"obj-1","url":"https://art.thewalters.org/images/art/foo.jpg","seq":1,"pageIndex":0,"type":"photo"}
```

### Build-side changes implied

- Add `type` to the `Page` entity (`PageType`, nullable, mutable) and to
  `FolioIngestService::PAGE_COLUMNS` + the `ingestPages()` insert tuple.
- In `ingestPages()`, when `mediaId` is absent, compute it via the shared
  `MediaIdentity` rather than leaving it null — so the join key is always present.
- Once every imaged row has a page, the read-time fallback in
  `FolioController` (use `row.dtoData.largeImageUrl` when `row.pages` is empty)
  becomes dead code — remove it after the normalizers are migrated, not before.

## Why this replaces the old cores+links model

Imagery used to live as separate cores wired with a `linkType: image` and one
`mediaFor` edge per object — ~1M link rows whose only job was sequencing. `Page` as
a `OneToMany` off `Row` *is* that, collapsed: the order lives in `seq`, there is no
link table and no linkType bookkeeping. Renaming `Page`→`Image` would not change any
of this; it would only make the page-shaped fields (`pageIndex`, `ledger`, `layout`,
OCR `text`) read oddly.

## Normalizer migration order (md repo)

Current state — nobody emits `page.jsonl`; producers and the consumer disagree on
the name:

- **Single image flattened onto the row** (Met, SMK, Cleveland, Victoria,
  Belvedere, Nomisma, Larco): set `largeImageUrl`/`iiifBase`/`thumbnailUrl` on the
  object row, no page core.
- **Walters**: separate `media.jsonl` + `link.jsonl` `mediaFor` edges (core named
  `media`).
- **museum-digital** (`MdNormalizeToJsonlCommand`): separate `images.jsonl` +
  `images[]` array on the row (core named `images`).
- **NARA**: doc metadata only.

Migration:

1. **Walters first** — it already has true multi-image data (`media.csv`,
   `isPrimary`/`rank`). Map its media rows → `page.jsonl` (`rank`→`seq`,
   `type=photo`), drop the `mediaFor` linkType. Best proof that multi-page works.
2. **A single-image source next** (e.g. Cleveland or Met) — emit one `page.jsonl`
   line per row from the existing single URL, `seq=1`, `type=photo` (or `scan`
   where appropriate). Proves the "single image is just one page" unification and
   lights up the AI button for the common case.
3. Roll the remaining single-image normalizers the same way; keep row-level
   `thumbnailUrl` but **derive** it from page[0] rather than hand-authoring it
   (search docs are flat and can't join the folio DB).
4. museum-digital `images.jsonl` → `page.jsonl` last (rename + key map).

Row-level `largeImageUrl`/`thumbnailUrl` stay as derived, flattened thumbnail
fields for Meilisearch; they stop being the source of truth for imagery — `Page` is.

## Implementation status (as built)

The contract and the shared producer now exist; normalizers are being migrated one by one.

**folio-bundle**
- `PageType` enum (`photo`/`scan`/`document`/`audio`/`video`/`other`) + a `Page.type`
  column, threaded through the whole `page.jsonl` contract: `PageDto`, `PAGE_COLUMNS`,
  and the `ingestPages()` insert tuple.
- `PageDto` (the per-line contract DTO; renamed from `PageRow` — a Page is not a `Row`)
  carries `FILENAME = 'page.jsonl'` so producer + consumer can't drift on the name.

**jsonl-bundle**
- `JsonlWriter::write()` accepts a serializable DTO (JsonSerializable, else public props
  with nulls dropped) — so a producer just does `$writer->write($pageDto)`; no
  normalize-to-array boilerplate.

**md (the producer side)**
- `App\Dto\SourceImage` + `App\Service\PageEmitter` — the one generic image step every
  normalizer reuses: dedupe, 1-based `seq`, `mediaId` (the join key), and the cover
  (first surviving image, returned for the row thumbnail). The per-source job is only
  "build the `SourceImage[]` list — where are my images, and what role is each."
- A normalizer fills `SourceImage[]` from its source and calls `PageEmitter::emit(...)`;
  source-specific policy (which images, drop archival PDF renders, canonical-large vs
  resized, IIIF vs direct URL) stays in the normalizer.

**Migration order (live)**
1. **Walters — done.** Emits one Page per `media.csv` Image row (`Rank`→`seq`, `type=photo`;
   PDF renders skipped). Verified: 100 objects → 243 pages, 51 multi-image (max 18),
   all linked in the folio. The multi-image proof.
2. **Cleveland — next.** Single direct image URL (`images.web.url`, not IIIF) → one Page
   per object, `seq=1`, `type=photo`. The "single image is just one page" proof.
3. Remaining single-image normalizers (Met, SMK, Victoria, …) the same way; derive
   row `thumbnailUrl` from the cover rather than hand-authoring it.
4. museum-digital `images.jsonl` → `page.jsonl` last.

Not yet done (deferred): `Page.type`/`media_id` width standardisation to 16, promoting
`MediaIdentity` into data-contracts, computing `mediaId` at ingest when absent, and
retiring the `FolioController` row-level-image fallback once every imaged row has a page.
