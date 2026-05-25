# ADR-0002: Folio Keyword Frequency & Word Cloud

**Status:** Proposed
**Date:** 2026-05-23
**Deciders:** Tac Tacelosky
**Context repo:** `survos-sites/showcase` (canonical)
**Related:** [ADR-0001 — Folio Chat & Search Architecture](./0001-folio-chat-and-search-architecture.md)

---

## Context

Museum collections benefit from at-a-glance summaries of their content — what subjects, places, people, and themes dominate. A word cloud is the canonical visual: term size scales with importance. This needs to work for every folio (thousands of collections, most with bot-only traffic) without per-folio AI cost at render time.

The folio already ships with SQLite FTS5; we should reuse that index rather than maintain a parallel keyword store.

---

## Decision

Two-tier word cloud, layered:

1. **Default cloud** — derived from FTS5's built-in `fts5vocab` virtual table, weighted by TF-IDF. Free, zero additional storage, available the moment a folio has an FTS5 index. Bots and casual visitors see this.
2. **Enhanced cloud** — derived from AI-extracted `keywords` produced as part of the existing `denseSummary` pipeline. Multi-word phrases, proper nouns intact, no stopwords. Researchers and partners see this when available.

Both are queried at request time from the folio's SQLite file. No precomputed cloud data structures.

---

## Tier 1: fts5vocab + TF-IDF

### Setup

`fts5vocab` is a built-in FTS5 companion virtual table that exposes the FTS5 index's term statistics. Created once alongside the FTS5 table; no storage cost (it's a view into the existing index):

```sql
CREATE VIRTUAL TABLE items_fts USING fts5(
  title, description, transcription,
  content='items', content_rowid='id'
);

CREATE VIRTUAL TABLE items_vocab USING fts5vocab('items_fts', 'row');
```

Granularity options:
- `'row'` — one row per term, total occurrences (`cnt`) and document count (`doc`). Use this for word clouds.
- `'col'` — same, broken down per column. Useful for "common in titles vs transcriptions" analysis.
- `'instance'` — every occurrence with position. Too heavy for word clouds.

### Naive frequency query (for reference, not recommended)

```sql
SELECT term, cnt, doc
FROM items_vocab
WHERE cnt > 5
  AND length(term) > 3
ORDER BY cnt DESC
LIMIT 100;
```

This produces a cloud dominated by genre-universal words ("letter," "dear," "page") rather than collection-distinctive ones. Don't ship this.

### TF-IDF query (recommended)

```sql
WITH stats AS (
  SELECT count(*) AS total_docs FROM items
)
SELECT v.term,
       v.cnt,
       v.doc,
       v.cnt * log(stats.total_docs * 1.0 / v.doc) AS tfidf
FROM items_vocab v, stats
WHERE v.doc > 2
  AND length(v.term) > 3
ORDER BY tfidf DESC
LIMIT 100;
```

This surfaces what's *distinctive* about the collection rather than what's most frequent in English. A Civil War letters cloud shows "regiment, wounded, homestead" rather than "letter, dear, yours."

**Caveats to verify on first implementation:**
- `log()` requires SQLite compiled with `SQLITE_ENABLE_MATH_FUNCTIONS` (default in recent builds; confirm on Dokku/Hetzner deploy). Fallback: register a custom function via PHP's `PDO::sqliteCreateFunction()`, or precompute IDF in PHP and pass per-term IDF values as parameters.
- `fts5vocab` column names (`term`, `doc`, `cnt`) are stable but worth a one-time `SELECT * FROM items_vocab LIMIT 1` sanity check against the [FTS5 docs](https://www.sqlite.org/fts5.html#the_fts5vocab_virtual_table) before building on top of them.
- The formula above is textbook TF-IDF. If clouds look noisy in practice, common variants (sublinear TF scaling, BM25-style saturation) are documented on the Wikipedia TF-IDF article.

### Stopwords and short-term filtering

The `length(term) > 3` filter is a cheap proxy for stopword removal. For better results:

- Maintain a small `stopwords` table per language (folio-level metadata can declare which language) and join/anti-join in the query.
- Or use FTS5's `tokenize = 'porter unicode61 remove_diacritics 2'` option at index time to handle stemming and diacritic normalization, then filter common stems.

For v1, `length(term) > 3 AND doc BETWEEN 3 AND (total_docs * 0.3)` is good enough. Iterate based on real folio results.

### Tokenizer gotcha

The `term` column returns tokens **as the tokenizer stored them** — lowercased, stemmed if stemming is enabled. So the cloud shows "soldier" even when the document said "Soldiers." Usually desirable (groups inflections); occasionally surprising (display capitalization is lost). If pretty casing matters, either title-case in the service layer or fall back to Tier 2 (AI keywords preserve casing naturally).

---

## Tier 2: AI-extracted keywords

### Schema addition

Extend the existing per-item AI generation (which already produces `denseSummary`) to also emit a `keywords` JSON array. Add as a new column on the `items` table:

```sql
ALTER TABLE items ADD COLUMN keywords TEXT;  -- JSON array
```

### Prompt extension

The summary prompt (`summary_v1` from ADR-0001) is updated to emit structured output including keywords. Bump to `summary_v2` to invalidate the content-hash cache cleanly:

```json
{
  "denseSummary": "Letter from Pvt. Henry Whitmore to his mother, dated June 3 1863, describing camp conditions near Vicksburg...",
  "keywords": [
    "Vicksburg siege",
    "Pvt. Henry Whitmore",
    "camp conditions",
    "Mississippi River",
    "1863"
  ]
}
```

Guidance to bake into the prompt:
- 3–8 keywords per item
- Multi-word phrases preferred over single tokens when the phrase is meaningful (`"Mississippi River"`, not `"Mississippi"` + `"River"`)
- Proper nouns preserved with original casing
- Date references as keywords when present (`"1863"`, `"June 1863"`)
- No generic descriptors (`"letter"`, `"photograph"`, `"document"`) — these add noise to the cloud

### Query

```sql
SELECT keyword, count(*) AS freq
FROM items, json_each(items.keywords)
WHERE items.keywords IS NOT NULL
GROUP BY keyword
ORDER BY freq DESC
LIMIT 100;
```

Cost: one `json_each` scan per cloud render. Negligible for collections under ~100k items. For very large folios, materialize into a `keyword_freq` table at folio-build time.

---

## Service Decomposition

Lives in `survos/folio-bundle`:

- **`WordCloudService`**
  - `tfidfCloud(Folio $folio, int $limit = 100): array` — Tier 1, returns `[['text' => 'vicksburg', 'value' => 47.3], ...]`
  - `keywordCloud(Folio $folio, int $limit = 100): array` — Tier 2, same return shape
  - `cloud(Folio $folio, int $limit = 100): array` — picks Tier 2 if `keywords` column is populated for >50% of items, else falls back to Tier 1

Return shape matches what every JS word cloud library (d3-cloud, react-wordcloud, wordcloud2.js) expects as input. The service layer renames `term`/`keyword → text` and `tfidf`/`freq → value`.

- **`WordCloudController`**
  - GET `/folio/{id}/cloud.json` — returns JSON for client-side rendering
  - GET `/folio/{id}/cloud.svg` — server-side SVG rendering (optional, useful for static / bot-cacheable output)

---

## Caching

The TF-IDF query is fast (sub-100ms for folios under 100k items) but not free. Cloud data changes only when the folio is rebuilt, so:

- HTTP cache: `Cache-Control: public, max-age=3600` on the JSON endpoint, keyed by folio version/build timestamp.
- Optional: materialize the cloud into a `folio_meta` table at folio-build time, alongside other folio-level summary stats (item count, date range, etc.). Trade-off: slightly larger folio file vs. zero query cost. Recommend deferring until query cost becomes visible.

---

## Cost Model

| Activity | When | Cost basis |
|----------|------|------------|
| Tier 1 (TF-IDF) cloud | Per cloud render | Zero (SQL against existing FTS5 index) |
| Tier 2 (keyword) cloud | Per cloud render | Zero (SQL against existing `keywords` column) |
| `keywords` field generation | Folio build | Marginal additional output tokens on the existing summary LLM call; cached by content hash same as `denseSummary` |

Net effect: word clouds add zero ongoing cost. The only AI cost increment is a slightly longer LLM output at folio-build time, paid once per item, cached across rebuilds.

---

## Implementation Order

1. **Add `items_vocab` virtual table** to the folio schema. One-line change in `survos/folio-bundle` schema definition.
2. **`WordCloudService::tfidfCloud()`** — Tier 1 query + result formatting. Ship this first; works for every existing folio with no AI cost.
3. **Extend `summary_v2` prompt** to emit `keywords` alongside `denseSummary`. Bump prompt version to invalidate cache.
4. **Add `keywords` column** to `items` table; populate on next folio rebuild.
5. **`WordCloudService::keywordCloud()`** — Tier 2 query.
6. **`WordCloudService::cloud()`** — auto-selection between tiers based on `keywords` population coverage.
7. **HTTP endpoints + caching headers.**
8. **Optional: server-side SVG rendering** for static/bot output.

---

## Open Questions

- **Language handling for stopwords:** folios are multilingual (per Museado's translation strategy). Should the cloud filter stopwords per-language, or accept multilingual noise in Tier 1? Tier 2 sidesteps this (LLM produces clean keywords regardless of source language). Leaning toward "Tier 1 is best-effort, Tier 2 is the quality path."
- **Cross-collection clouds:** for the Meili object-type indexes (photos, wills, newspapers), a cross-collection word cloud would be useful. Probably belongs in a separate `MeiliWordCloudService` using Meili's facet distributions rather than FTS5 vocab. Out of scope for this ADR.
- **Click behavior:** when a user clicks a cloud term, default UX is "search this term in the collection." Tier 1 terms map cleanly to FTS5 queries; Tier 2 multi-word phrases need quoting (`MATCH '"vicksburg siege"'`). Worth a small UI/UX spike.

---

## References

- [SQLite FTS5 `fts5vocab` documentation](https://www.sqlite.org/fts5.html#the_fts5vocab_virtual_table)
- [SQLite math functions](https://www.sqlite.org/lang_mathfunc.html)
- ADR-0001 — Folio Chat & Search Architecture (denseSummary pipeline, content-hash caching)
- TF-IDF variants — Wikipedia "tf–idf" article lists the common formulas if the textbook version proves noisy