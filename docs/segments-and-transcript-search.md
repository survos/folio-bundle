# Segments and transcript search

**Status:** design note, nothing implemented. Written 2026-09-25 from measurements on a live
cluster and the folio code as it stands. Argue with it before building.

## The problem in one line

Folio search was built for museum objects — many facets, a one-line description — and transcripts
are the exact inverse: a handful of facets and almost all the value in the text.

## What is true today (measured, not assumed)

### The ES index is Meilisearch wearing an ES costume

`FolioElasticBuildSetCommand`'s own docblock calls itself "the twin of
`FolioMeiliBuildSetCommand`" — same `FolioDocumentStream`, same `--fields` projection, same
`--extras` lift, straight into `_bulk`. Its stated mapping rule is Meili's filterable-attributes
model transliterated: *"the fields a reader searches are `text`, everything else is `keyword`, and
a facet is the field itself."*

On the live `zm_folio_object_20260915162531` index (180,212 docs):

| | |
|---|---|
| analysis config | **none** — default `standard` analyzer |
| field types | 10 `text`, 12 `keyword`, 1 object, 1 long |
| multi-fields | every `text` field carries a `.keyword` twin (the faceting workaround) |
| shards | 1 / 1 replica |
| `semantic_text` / `sparse_vector` / `rank_features` | **zero, cluster-wide** |
| providers present | mus, smith, fpeu, wiki — **no `loc`** |

No stemming and no stop words means "childcare" does not match "child care", and "closed" does not
match "closing". For a 40-word museum description that is fine. For a 50-minute interview it is not.

### The index is row-shaped by design, not by configuration

`FolioDocumentStream` says *"Streams folio rows as flat search documents"* and selects
`FROM item i JOIN core c`. Pages are touched exactly once, to pick a thumbnail:

```sql
(SELECT p.url FROM page p WHERE p.row_id = i.id ORDER BY p.seq LIMIT 1) AS page_url
```

`--core` and `--contentTypes` choose *which items*, never a different granularity. `dialogue` is
never read. There is no knob that emits a document per page or per segment.

## Recommendation: a dedicated `Segment`, hanging off `Page`

**`Segment` is to `Page` what `Page` is to `Row`.** Chain: `Row → Page → Segment`.

This is already half-true in the schema: `Page::$dialogue` is a JSON column holding
`list<{speaker, text, startMs, endMs}>`. The model already says segments belong to a page; it just
cannot query them. Promoting that column to rows is the whole change, and the parent is decided.

### Why not reuse `Page`

1. **`url` is non-nullable and is the identity anchor.** `mediaId = xxh3(url)` joins media-bundle
   and its S3 AI sidecars. A segment has no url of its own — it is a slice of its parent's media.
   Reusing `Page` means fabricating urls or making `url` nullable, and the latter breaks `mediaId`.
2. **Roughly half of `Page` is pixel-space baggage** — `sourceUrl`, `mediaId`, `layout` (bbox),
   `width`, `height`, `htr`, `pageIndex` ("0-based index within the source binary"). None of it
   means anything for a turn of speech. A segment's anchors are time (`startMs`/`endMs`) or
   character offset, plus `speaker`, which has no page analogue.
3. **`Page`'s own docblock scopes it:** *"Media will later generalise to audio/video; for now a
   Page is an image page."*
4. **Cardinality would leak into museum folios.** One Pearl Harbor interview is 516 turns; VHP at
   99,451 interviews is tens of millions. Those would land in the same `page` table every museum
   folio queries — and the thumbnail subquery above would happily return a dialogue turn. You would
   be adding `WHERE type NOT IN (…)` to every page query in the viewer, the stream and the API.

### The honest counter-argument

`Page` already has `text`, `denseSummary`, `seq` and the row relation. You could ship transcript
search tomorrow by indexing `page.dialogue` JSON with no schema change. If the goal is a demo, that
works. The cost arrives later and lands on museum folios that have no stake in transcripts.

### Shape

Reuse the patterns that already work: composite id `{pageId}#{seq}`, `seq` ordering, per-segment
`denseSummary`, a `SegmentDto` mirroring `PageDto` through the same emitter, and a
`FolioSegmentStream` sibling feeding the same `_bulk` plumbing. Museum objects simply have no
segments, exactly as rows with no pages have no pages.

It generalises the way you want: an **audio** page's segments are speaker turns; a **scanned**
page's segments are paragraphs or ALTO blocks (what `layout` half-does today, in pixel space).
NARA documents, vox and transcripts get segments; museum objects do not.

## Two indices, not one

- **row index** — what exists now. Keyword-heavy, facets, unchanged. Museum objects untouched.
- **segment index** — few facets (`rowId`, `pageId`, `speaker`, `folioCode`), one dominant text
  field with a *real* analyzer (stemming, stop words, ideally synonyms), plus a vector field, and
  hybrid retrieval combining them (RRF). Highlighting comes free and carries the provenance that
  citations need.

Bolting this onto `FolioDocumentStream` would be wrong regardless of the Page/Segment decision —
different unit, different mapping, different analyzer.

## Licensing: semantic search is free, ELSER is not

The cluster is ES **9.5.3 on a basic licence**, and `_xpack` reports `ml.available = false`.
`.elser-2-elasticsearch` **is** registered as an inference endpoint (sparse_embedding, adaptive
allocations 0–32) but a basic licence will not run the model.

**Measured on this cluster, 2026-09-25:** created an index with a `dense_vector` field
(`dims: 4, index: true, similarity: cosine`), indexed a doc, ran a `knn` query — **it worked**,
score 0.9989. Probe index deleted.

So the split is:

| | licence |
|---|---|
| `dense_vector` + kNN search | **free (basic)** |
| BM25 with custom analyzers, synonyms, highlighting | **free (basic)** |
| RRF / hybrid retrieval | verify — believed basic, not tested |
| ELSER / `semantic_text` (ES generates embeddings in-cluster) | **paid** |

**Conclusion: generate embeddings outside ES and vector search costs nothing, at any dataset
size.** That is already within reach — Ollama runs locally with capable models, and mediary
already does batch AI in prod. ES's `_inference` API can also proxy an external service
(openai/cohere/hugging_face) if in-cluster orchestration is wanted later.

Untested alternatives if ES stops fitting: pgvector in the Postgres already running, or Qdrant /
Vespa / Typesense. Not investigated.

## Segmentation

`vanderlee/php-sentence` (in `~/sites/lingua`, `src/Controller/AppController.php`) is the "better
segmenter" — third-party, a *sentence* splitter. It solves the document case.

Dialogue is the opposite problem: it arrives **pre-segmented** by speaker turn, and turns are
wildly uneven — from "Pardon? No, no." to several hundred words. A four-word turn is not a
retrievable unit. The algorithm needed is **merging** short turns into windows large enough to
retrieve on while preserving speaker boundaries and provenance. That does not exist yet anywhere in
these projects.

## Open questions

1. Window size and overlap for dialogue merging — needs measuring against real queries, not chosen.
2. Does RRF require a paid tier? Believed not; untested.
3. Which embedding model, and generated where — Ollama locally, mediary batch, or an API.
4. Does the segment index live in the same tenant set as the row index, or separately? Depends on
   the tenant/token model, which this note's author does not know well enough to judge.
5. Is `Segment` the right name given `layout` blocks already exist for scans? Possible overlap.

## Provenance of the numbers here

`217` COVID interviews with transcripts, `57` mentioning childcare across `115` passages
(~102k chars), `516` turns in one Pearl Harbor interview, `99,451` digitized VHP items —
all measured in the session of 2026-09-25. The childcare retrieval that motivated this note was a
hand-written regex, which is precisely the thing semantic retrieval removes.
