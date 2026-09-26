# Blocks, windows and article search

**Status:** design note, nothing implemented. Written 2026-09-25 from measurements on a live
cluster and the folio code as it stands. Argue with it before building.

## The problem in one line

Folio search was built for museum objects — many facets, a one-line description — and newspaper
articles and transcripts are the exact inverse: a handful of facets and almost all the value in the
text.

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

## Recommendation: `Row → Block`, with an anchor — not `Row → Page → Segment`

*Revised 2026-09-25, same day. The first draft hung `Segment` off `Page`. Newspaper articles — the
real target, ahead of transcripts — do not fit that chain, and measuring them showed why.*

### Why Page cannot be the parent

- **Born-digital articles have no pages.** `news/rappnews-digital`: 534 article rows, **0** page
  rows. The text is `bodyText` (Markdown) on the row itself. There is nothing to hang a segment on.
- **OCR'd articles already misuse Page.** `news/rappnews5254`: each of 24,554 article rows owns a
  Page that is a *copy of its whole issue page* — 1,548 distinct urls and mediaIds across 24,554
  rows, ~16 articles per page image. So `WHERE media_id = ?` in `FolioIngestService` (lines
  643/667/680) already fans out to every article on that page, and every article's "thumbnail" is
  the full broadsheet. The article does not own a page; it occupies a *region* of one.
- **Audio already works as a Page.** `PageType::Audio` exists and Pearl Harbor has 84 audio pages
  (81 with `dialogue`). The class docblock ("for now a Page is an image page") is stale. Page is
  the right *media* unit; it is the wrong *meaning* unit.

What each case actually needs is a reference **into** media, not ownership **of** it.

### The model

- **Row** — the unit of meaning (an article, an interview, a letter). Already exists.
- **Block** — a typed piece of a row: `{role, text, anchor}`. The anchor says where it came from:

  | source | anchor |
  |---|---|
  | born-digital article | byte range `[from, to)` in the row's text |
  | OCR'd article | `pageId` + bbox (what `page.layout` blocks already carry) |
  | transcript | `pageId` + turn range + `startMs`/`endMs` |

- **Window** — body blocks packed to a retrievable size. The unit that goes to the search index.
  Derived, not stored: it is a pure function of the blocks plus size settings that are still being
  tuned. Its id encodes its anchor (`{rowId}~{from}-{to}`, `{pageId}~t19-t32`), so every hit
  resolves back to the source without a table.

Page becomes something a block *points at*. Museum objects have no blocks, exactly as they have no
dialogue today, so nothing leaks into the queries they run.

**Does Block need a table?** Not to start. Digital blocks are recomputed from `bodyText`, OCR blocks
already live in `page.layout`, turns in `page.dialogue`. A table earns its place once blocks carry
something expensive or human-authored — a reviewed role, an AI summary, an article-grouping
decision on OCR blocks. Embeddings do not force it: cache them by `xxh3(window text + model)`.

### Block roles

Headline, subhead and byline are different kinds of block, not body text that happens to be short.
Only **body** blocks are packed into windows; the others travel with the window as fields or
metadata. Measured on `rappnews-digital` (spike: `docs/spike/article-windows.php`):

| role | where it lives in this source | count / example |
|---|---|---|
| headline | row `title` | 1 per row |
| byline | row `creators`; rarely in body | 2 in-body: `By Helen Williams` |
| caption, credit | row `articleImages[]`; credit sometimes in body | 4 in-body credits |
| subhead | `### Building permits` (hard boundary), `**Jackson**` (minor) | 796 |
| deck | italic note before the first body block | 64: `*From staff and contributed reports*` |
| dateline | bare date line in history columns | 48: `January 19, 1961` |
| signature | italic `— Name` closing a letter | 20 |
| aside | short italic paragraph mid-article | 100 |
| body | everything else, list items included | 6,568 |

The same vocabulary is what OCR has to produce. Today every `page.layout` block is typed
`paragraph`, even though `fontSize` / `maxFontSize` are on each one (headline ≈ font lift ≥ 6).
Assigning roles to OCR blocks is the bridge between scanned and digital papers.

### Two meanings of "segmentation" on OCR papers

1. **Blocks → articles** (grouping). Unsolved; ad classification waits on it too. OCR only.
2. **Article → windows** (packing). Solved by the spike for any source whose blocks have roles.

Born-digital articles skip (1) entirely, which is why they are the place to build (2) first.

## Two indices, not one

- **row index** — what exists now. Keyword-heavy, facets, unchanged. Museum objects untouched.
- **window index** — few facets (`rowId`, `folioCode`, `sections`, `date`, `bylines`), a `headline`
  and `subheads` field that can be boosted, one dominant `body` field with a *real* analyzer
  (stemming, stop words, synonyms), plus a vector field, fused by rank client-side (see Licensing).
  Highlighting carries the provenance citations need.

Bolting this onto `FolioDocumentStream` would be wrong regardless: different unit, different
mapping, different analyzer.

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
| `rrf` and `linear` retrievers | **paid** — 403 `current license is non-compliant for [Reciprocal Rank Fusion (RRF)]` (same for `linear retriever`) |
| `query` + `knn` in one request (boost-weighted score sum), `knn` inside `bool` with filter + highlight | **free (basic)** |
| ELSER / `semantic_text` (ES generates embeddings in-cluster) | **paid** |

**Conclusion: generate embeddings outside ES and vector search costs nothing, at any dataset
size.** That is already within reach — Ollama runs locally with capable models, and mediary
already does batch AI in prod. ES's `_inference` API can also proxy an external service
(openai/cohere/hugging_face) if in-cluster orchestration is wanted later.

**Measured 2026-09-25 (RRF):** on the same cluster, both the `rrf` and `linear` retrievers are refused
with a 403 licence error. What does run on basic is the older form — `query` and `knn` in one
request, or a `knn` clause inside `bool.should` with filters and highlighting — but that *sums
scores*, it does not fuse ranks. BM25 is unbounded and cosine is 0..1, so fixed boosts drift per
query. The free way to get real rank fusion is to run the BM25 and kNN searches separately and fuse
by rank in PHP (a few lines at our result sizes). Probe index deleted.

Untested alternatives if ES stops fitting: pgvector in the Postgres already running, or Qdrant /
Vespa / Typesense. Not investigated.

## Windowing (spike: `docs/spike/`)

`windows-lib.php` is a source-neutral packer: it sees units with a word count, a *cut score* (how
good it is to start a window at this unit) and optional hard boundaries. Once a window reaches the
target size it closes at the next preferred head, else at the next acceptable cut, and never
exceeds the max. Adapters turn sources into units:

- **`article-windows.php`** — Markdown `bodyText` → role-typed blocks; `###` subheads are hard
  boundaries, `**minor**` subheads preferred heads; long paragraphs split by sentence
  (`vanderlee/php-sentence`). 534 articles → 1,847 windows at target 120 / max 250, median 143
  words, 147 articles fit in one window. The 61 under-minimum windows are whole column items
  ("Sympathy", "Prayer list") — in sectioned content a subhead defines a window regardless of size.
- **`dialogue-windows.php`** — speaker turns; a question heads a window, a backchannel never does,
  recording cuts (`Cut A1`) are hard boundaries. Pearl Harbor: 1,938 turns → 346 windows.

A sentence splitter solves only the long-paragraph case; the real work is merging short units.

## Measured retrieval (2026-09-25)

`docs/spike/article-search-eval.php` scores settings against `article-queries.json`: 31 judged
queries over `news/rappnews-digital`, in five kinds — *article* (one story in a reader's words),
*passage* (a fact inside a column), *topic* (several stories), *vocab* (words the text does not
use: "daycare", "fifty years ago", "car wreck") and *name*. Everything is scored at the **article**
level, which is what a reader gets back. Vectors: local `nomic-embed-text` (1,850 windows in ~25 s;
it is a stand-in, not the answer to open question 3).

**Windows are what make vectors work.** One document per article vs 120-word windows:

| | whole article | 80 / 160 | **120 / 250** | 200 / 400 |
|---|---|---|---|---|
| kNN MRR@10 | 0.742 | 0.929 | **0.933** | 0.888 |
| kNN on *passage* queries | 0.45 | 0.93 | **1.00** | 0.86 |
| BM25 (flat) MRR@10 | 0.814 | 0.827 | 0.840 | 0.859 |

(26-query set, before the name queries and number synonyms were added.) BM25 barely cares about
window size; kNN on whole articles is diluted by everything else in the article.

**Settings at 120 / 250, full 31 queries:**

| setting | MRR@10 | Hit@1 |
|---|---|---|
| BM25, headline ×3 (first probe) | 0.791 | 0.68 |
| BM25, no boost | 0.872 | 0.81 |
| kNN | 0.944 | 0.90 |
| RRF, windows fused then shown in order (first probe) | 0.962 | 0.94 |
| RRF, grouped by article first, headline ×1.5, kNN ×1 | 0.962 | 0.94 |
| **RRF, grouped first, headline ×1.5, kNN ×1.5** | **0.984** | **0.97** |

Read these with the sample size in mind: one query moving one rank is ~0.02 MRR. What is solid:

- **Headline boost ×3 hurts** (a gift-themed letter outranked the deed-gift records on its title).
  Keep it ≤ 1.5.
- **Group by article before fusing**, so one long article cannot fill the page; the reader sees each
  article once with its best passage (window id → byte range to highlight).
- **Fusion beats either list** once the analyzer is right: BM25 contributes exact names and numbers,
  kNN contributes paraphrase ("electric co-op restores power" → "REC update on outages").
- **Search-time synonyms earn their keep**: number words ⇄ digits took "what happened fifty years
  ago" from rank 7 to 1; `childcare, day care` and `broadband, high speed internet` are the pattern.

Still wrong: "how many people live in Rappahannock now" (kNN finds the census story first; BM25 has
no "population" and drags the fused rank to 6), and terse record lists like Courthouse Row
("deed gift") rank low for natural-language questions about them.

`docs/spike/article-search.php` runs these settings as a command. It builds a probe index (`--keep`
to reuse it with `--index=`) and prints each article once with its best passage.

### OCR newspapers: block tier vs full tier (rappnews5254, 1952–54)

`docs/spike/ocr-windows.php` turns an OCR folio into the same windows, at either tier;
`r5254-queries.json` holds 23 known-item queries (10 answered inside a headline-stitched story, 13
in a loose block), relevance by a distinctive phrase of the target's body so a story and its
member blocks are scored alike. Same eval, same fusion.

| index | MRR@10 | story queries | block queries |
|---|---|---|---|
| block tier — 24,554 blocks, untitled | 0.71 | 0.52 | 0.86 |
| full tier — 681 stitched stories + 23,263 loose blocks | **0.86** | **0.86** | 0.86 |
| full tier, stitched ad groups excluded | 0.88 | 0.91 | 0.86 |

The free headline-span stitch covers little of the 1950s and most of what it assembles is
advertising (392 of 681 groups, now typed so by the enhancement), yet it lifts search on the stories
it does assemble from 0.52 to 0.86 and costs nothing on the rest. **On sparse 1950s stitching a strong
headline boost helps** (×3: 0.859 vs no boost 0.757; but see 1996 below) — the reverse of born-digital articles, because a stitched
headline is the one clean line in noisy text. So boost `headline` only where it is trustworthy (full
tier, or block tier with `titleSource` `lead`/`block`) and never on block-tier text titles. Two
queries fail in both tiers because their target text is OCR-garbled; that is the paid pass's job.

**Denser stitching changes the headline answer (rappnews4909, 1996).** On the rebuilt
`rappnews4909-enhanced` (1,880 stories claiming 6,196 of 18,469 blocks in 1996), 22 judged queries,
`r4909-1996-queries.json`:

| index | best MRR@10 | story queries | loose-block queries |
|---|---|---|---|
| block tier | 0.63 | 0.52 | 0.76 |
| full tier, headline ×1.5, kNN ×1.5 | **0.76** | **0.79** | 0.72 |
| full tier, headline ×3 | 0.68 | 0.82 | 0.52 |

Where stitched stories are common, a ×3 headline boost lets them crowd out the loose blocks, so the
default is the digital one after all: headline ×1.5, kNN weighted 1.5 — boosting only trustworthy
titles still holds. One loose-block "miss" was a column continuation the stitcher did not join
(a story ending mid-clause, the next block finishing the sentence); the rest were related stories
from other weeks.

Harvest side (same day, coordinated): `AltoParser` records `lead` (a headline/deck/byline set
inside a block, boxed by its own lines) and `role` per block (harvest eea3ab8); block-tier titles
come from the lead with `titleSource` (709bc69); stitched stories carry a `headline` segment and
ad groups are `recordKind: advertisement` (1979139). Model agreed: source blocks belong to the
issue; an article row references an ordered list of block ids plus grouping evidence; loose blocks
are never promoted one-per-row.

## Open questions

1. Window size and overlap — **120 / 250 wins on the current 31 queries** (see Measured retrieval);
   overlap untested. Re-measure when the query set grows or the source changes (OCR, transcripts).
2. ~~Does RRF require a paid tier?~~ **Yes** — measured 2026-09-25, see Licensing.
3. Which embedding model, and generated where — Ollama locally, mediary batch, or an API.
4. Does the window index live in the same tenant set as the row index, or separately? Depends on
   the tenant/token model, which this note's author does not know well enough to judge.
5. ~~Is `Segment` the right name given `layout` blocks already exist for scans?~~ They are the
   same thing: `layout` blocks are the OCR half of Block. Name them Block; add `role`.

## Provenance of the numbers here

`217` COVID interviews with transcripts, `57` mentioning childcare across `115` passages
(~102k chars), `178` turns in the longest Pearl Harbor interview (an earlier `516` was wrong; COVID's longest is `732`), `99,451` digitized VHP items —
all measured in the session of 2026-09-25. The childcare retrieval that motivated this note was a
hand-written regex, which is precisely the thing semantic retrieval removes.
