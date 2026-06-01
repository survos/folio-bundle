# Term Sets & Facets — architecture + status

Session handoff for the term-set / facet / harvest-dataset work. Companion to
[folio-build-pipeline.md](folio-build-pipeline.md).

## The big decisions (settled)

1. **Term-set fields are flagged declaratively** on `MuseumVocab` via `#[VocabTerm(termSet: true)]`
   (`mono/lib/data-contracts`). No per-dataset `--fields`. Flagged so far: **cul, tec, mat, med, pla,
   period, epoch, coll, dept**. (per/obj/org/subject stay unflagged — entity-like, cores/relations.)
2. **Term sets are facets by definition.** A `termSet`-flagged field is exposed as a searchable folio
   facet automatically (see snapshotter below).
3. **Term extraction is a byproduct of profiling — one scan, not two.** The `JsonlProfiler` already scans
   every row to compute `distinct`/`distinctCapReached`; it now **retains the distinct values** for
   low-cardinality fields (`distinct ≤ cap`). Term derivation reads the **profile**, never re-scans.
4. **Terms are single-language.** Multilingual values are translations (→ translation memory), handled
   separately — not extra terms. So no per-language split in term extraction.
5. **Slug is term identity.** Values sharing a slug are one term; label = best/most-consistent variant.
   Use Symfony **`AsciiSlugger`** (transliterates accents/CJK), not a regex.

## What's DONE (this work)

- `VocabTerm` gained a `termSet` bool; flagged the 9 fields above. (`data-contracts`)
- `JsonlProfiler::__invoke` (jsonl-bundle) — line ~147: instead of unconditionally
  `unset($fieldStats['distinctValues'])`, **retains** `distinctValues` (as a value list) when
  `!distinctCapReached`; capped fields keep only the count. Scalars expose `distinctValues`; arrays
  expose `arrayStats._elemDistinctValues` (value→true map). `normalizeDistinctKey` is `(string)$value`,
  so scalar keys are the original values.
- `TermSetExtractor` (`mono/bu/data-bundle/src/Service/TermSetExtractor.php`) — `extractFromProfile($normalizeDir)`:
  reflects `VocabTerm.termSet` fields, reads `obj.profile.json` distinct values, slug-dedups via
  `AsciiSlugger`, picks best-cased label, writes `termSet.jsonl` + `term.jsonl` (SMK format), merge-safe.
- `FolioSchemaSnapshotter::snapshot` (folio-bundle) — after writing `schema_property`, runs
  `UPDATE schema_property SET facet=1, filterable=1 WHERE name IN (<termSet codes>)` via a
  `termSetCodes()` reflection helper. **This is the right layer** (runtime, observed fields) — NOT the
  compile-time `MeiliIndexPass`, which only sees DTO-declared fields on Doctrine-entity indexes.

  Flow: `schema_property.facet` → `FolioFtsIndexer` builds `item_facet`/`item_facet_count`
  (`WHERE facet=1 OR filterable=1`, FolioFtsIndexer:147) → folio search sidebar. To see new facets on a
  folio, **rebuild it** (`folio:build --dataset=…`) so snapshot + facet rebuild re-run.

## What's PENDING

1. **Wire term extraction to run automatically** on `ImportConvertFinishedEvent` (end of import:convert)
   → call `TermSetExtractor::extractFromProfile()`. **Retire `VocabTermExtractorListener`'s separate
   full scan** (`mono/bu/data-bundle/src/EventListener/`). Reconcile its `30_terms/<type>.<lang>.jsonl`
   `vocab:map` AI inventory — produce it from the profile too (lang-less) so `vocab:map` keeps working.
   Its hardcoded `TERM_FIELDS` map is superseded by the `termSet` flag.
2. **Step 3 — folio ingest relation.** `FolioIngestService::ingestTerms` already loads termSet/term.jsonl;
   add: for each row, resolve each flagged field's value (by slug) → the term and create the obj→term
   relation, so the facet links to the term (translation/display/help).
3. **license + imageCount as facets** — values are set (Cleveland `shareLicenseStatus`→license; imageCount
   per row). These are DTO fields, so flag them facet in `mono/lib/data-contracts` (BaseItemDto uses
   `rights`/`rightsUri`; the `Field` attribute). `imageCount:0` must be searchable.
4. **Cleveland creators link** — `creators` is slimmed to ids in the raw pass but shows as bare numbers;
   emit `linkType.jsonl` (createdBy obj→per) + `link.jsonl`, SMK-style (`Smk::writeDerivedLinks`).
5. **external_resources** — generic handling (Cleveland wikidata Q-ids done; internet_archive + others;
   recurs across datasets).
6. **AWMM port** — paginate offset API (`opacobjects`), flatten `opacObjectFieldSets`, Flickr +
   `imagesCollection` MEDIUM images, sparse raw; normalizeRow maps; resume/retry; retire mus `InitAwmm`;
   whitelist host. Mechanical from the working `InitAwmmCommand`.
7. **multi-fetch-bundle** — reparent to `Survos\Kit\AbstractSurvosBundle`, agent README, sequential
   paginated-fetch API (separate session).
8. **SMK term-set codes** (`technique`/`material`/`collection`) → convention codes (`tec`/`mat`/`coll`).
9. **harvest cache:clear** dies on `field-bundle` double-link → `mono/link .` in harvest (env).

## Harvest dataset ports (the pattern)

Port mus `Init*Command` → harvest `src/Dataset/*.php`: **fetch writes raw, `normalizeRow` maps; drop
pixie/translations.** Fetch: paginate API (resume from sidecar via `JsonlCountService::rows` + retry on
transient errors) or pull a bulk file; write **sparse** raw via `Arrays::sparse`; then `import:convert`.
Images: set `largeImageUrl`/`thumbnailUrl` (NOT `iiifBase` for non-IIIF CDN URLs — that gets `/full/…`
appended → 403; imgProxy handles the direct URLs). Read **post-RowNormalizer** keys (snake→camel) — check
the `.profile.json`, not the raw data.

- **Victoria** — done (page API, resume+retry, sparse, normalizeRow, fail-on-missing-id).
- **Cleveland** — done (bulk `data.json` via GitHub LFS media URL, no clone; creators→ids in raw pass;
  images via imgProxy; `measurements`→DIMENSIONS→`DimensionsNormalizer`; license; wikidata; `Arrays::sparse`).
- **AWMM** — pending (see #6).

## Key files

- `mono/lib/data-contracts/src/Attribute/VocabTerm.php` (`termSet` flag)
- `mono/lib/data-contracts/src/Vocabulary/MuseumVocab.php` (flagged consts)
- `mono/bu/jsonl-bundle/src/Service/JsonlProfiler.php` (retains distinct values)
- `mono/bu/data-bundle/src/Service/TermSetExtractor.php` (profile-based term build)
- `mono/bu/data-bundle/src/EventListener/VocabTermExtractorListener.php` (to retire/repurpose)
- `mono/bu/folio-bundle/src/Service/FolioSchemaSnapshotter.php` (termSet⟹facet)
- `mono/bu/folio-bundle/src/Service/{FolioFtsIndexer,FolioIngestService,FolioArchiveService}.php`
- `harvest/src/Dataset/{Victoria,Cleveland,Awmm,Smk}.php`, `harvest/src/HttpClient/ForceFreshHttpClient.php`
