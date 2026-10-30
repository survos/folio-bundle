# Building folios

Folio consumes explicit JSONL files and metadata. It does not discover datasets,
select pipeline stages, query a dataset registry, or advance a workflow.

Inject `FolioBuilder` and call `build(FolioBuildInput $input, bool $archive = false)`.
The input specifies the folio code, row-core files, metadata and source language.
Optional inputs include a target locale, page/term/link/claim files, translation
files keyed by language, and a reviewed translation override file. Supplied files
must exist and be readable. Omit optional streams when they are absent.

```php
use Survos\FolioBundle\Dto\FolioBuildInput;

$result = $builder->build(new FolioBuildInput(
    code: 'example/collection',
    sources: ['obj' => '/data/objects.jsonl'],
    metadata: ['label' => 'Example collection'],
    sourceLocale: 'en',
), archive: true);
```

The builder ingests into a temporary folio, saves a bare snapshot, optionally
compresses an archive, adds indexes/FTS/views, finalizes SQLite, and atomically
installs the working file. Empty or failed builds throw; they do not replace the
live working folio. The result contains working/bare/archive paths, row counts,
metadata and the built locale. The caller owns publication and registry updates.

Harvest's `DatasetFolioInputFactory` selects pipeline inputs, including the existing
freshness and materialized-assembly checks. `DatasetFolioBuilder` runs declared
locales and registers artifacts before announcing `FolioPublishedEvent`. The
DatasetInfo workflow, previews and completed English translation builds use it.
The shared bundle has no dependency on Harvest or DatasetBundle.

## Supported entry points

- Harvest: acquire durable raw, then `dataset:register provider/code`; use
  `--rebuild` to queue normalization through the workflow while retaining the
  published folio. This is asynchronous, not an inline build recipe.
- Readers: `folio:pull`, `folio:info`, `folio:browse`, and existing file/search tools.
- Metadata migration: `folio:migrate --file=/path/to/existing.folio`.
- Validation: `folio:validate` checks files; registry lookup is removed. Orphan
  detection uses a configured dataset API catalog when available, never guesses
  from an absent registry.
- Meili indexing: `folio:meili:build-set`; pass `--locale` when a language hint is
  known. No language is inferred from a dataset database.

Removed, without replacement commands: `folio:build`, `folio:archive`,
`folio:translate`, `folio:ingest`, and registry-wide `folio:migrate` selection.
Harvest also removes `dataset:scan`, `dataset:folio`, and stage commands' `--folio`
bypass. Archive and ingest engines remain services. Existing folio archives can
still be restored; rebuilding uses the owning application's workflow.

Sets and sitemaps use the published catalog. Sitemap entries additionally require
a local folio file. The dataset-registry collection controller/table is removed;
applications own their catalog UI. `folio_sets_source: registry` is no longer
supported; configure a catalog instead.

Producer configuration moves with the caller: Harvest uses `FOLIO_BUILD_ARCHIVE`
for its adapter and `app.reviewed_translations_dir` for input selection. The old
`survos_folio.build_archive` and `survos_folio.reviewed_translations_dir` options
are removed. Other producers pass archive preference and reviewed files directly.


## Archive and serving representations

The durable artifact remains `.folio.gz`. The planned publication pipeline builds
`.folio`, converts it to `.duckdb`, compresses and uploads the folio archive using
the existing archive publication flow, and uploads DuckDB separately under an
immutable S3 key for remote access. The current adapter still performs archive
creation inside the SQLite builder; coordinated DuckDB S3 publication is not yet
wired. A restored folio contains the build identity and DTO data needed to regenerate
DuckDB without Harvest, source downloads, or enrichment services.

Elasticsearch remains the application full-text search representation. FTS5 is
outside this companion contract; CMA builds with FTS disabled. Removing legacy
FTS paths throughout reader applications is a separate migration. DuckDB, ES,
and future PostgreSQL projections can all be rebuilt from the folio archive.

## DTO-driven DuckDB companion

`FolioBuilder` stamps a fresh `_folio_build` identity (build ID and export schema
version) before taking the bare/archive snapshots. `DuckDbFolioExporter::export()`
reads the finished SQLite folio in one read transaction and atomically publishes
its `.duckdb` sibling. This first implementation intentionally exports the finished
DTO-normalized content; direct materialization from the same producer contract can
follow without changing consumers. `DuckDbFolioBuilder` below remains a separate
input snapshot tool, not the companion exporter.

`main` preserves every logical SQLite table, including generated column values,
relations, pages, claims, translations, metadata, and original DTO/extras JSON text.
SQLite internal tables, virtual FTS tables, shadow tables, views and indexes are
not copied. This is content parity, not SQLite SQL/FTS or ORM emulation.
`dto.<contentType>` materializes all public stored DTO properties, including
unobserved fields, with `_itemId` and `_coreId` joins to `main.item`. Scalar types
come from PHP declarations. Explicit PHPDoc lists of scalar values become native
DuckDB lists. Unspecified/heterogeneous arrays remain JSON rather than guessing
an element schema. `_dto_schema` records each class and its transform contract.
Sparse serialization becomes NULL in typed columns; `item.dto_data` retains exact
presence and JSON text. Invalid typed values fail the export; no TRY_CAST fallback.

```php
$ctx = $folios->context('mus/cleveland');
$em = $ctx->em; // Existing Doctrine behavior is unchanged.
$pdo = $ctx->duckdb(); // Lazy, read-only, optional native PDO.
```

Selection captures the SQLite build identity without opening DuckDB. A missing
companion or an older SQLite folio without an identity returns null. An existing
companion with a mismatching/unsupported identity throws when explicitly opened.
Missing PDO support therefore does not break ORM-only readers. Select a fresh
context after publication to see a new revision. Publication of the two files is
not one filesystem transaction; identity checks make the intervening mismatch
explicit. Legacy raw snapshots have no matching identity and must be rebuilt.

`DuckDbFolioComparator::compare()` compares every logical table value with ordered cursors,
and every DTO projection by stable item identity. It checks duplicates, nulls,
generated values, and the original JSON text as well as typed columns. DTO JSON
comparisons normalize object-key order and equivalent integral float encodings.
A changed value with unchanged counts fails. No external search index participates.

## Optional local DuckDB snapshot

`DuckDbFolioBuilder::build(FolioBuildInput $input, string $target)` consumes the
same explicit source-language inputs and creates a closed `.duckdb` file locally.
It does not advance DatasetInfo, change the SQLite builder or publish to storage.
The caller owns the destination and any later upload. Create its parent directory
on local disk before calling the builder. A failed/empty build preserves an existing
snapshot; a successful build checkpoints, closes and atomically renames its private
working file into place.

The main schema contains one columnar table per row core (`obj`, `doc`, etc.).
`aux` contains page/term/link/claim/phrase streams, and `translation` contains the
supplied translation streams. `raw_core`, `raw_aux`, and `raw_translation` retain
the original JSON objects, including absent versus explicit null fields. `_folio`
stores metadata and the engine version; `_streams` records source paths and counts.
Keeping both original JSON and columnar tables costs space but preserves all input
data while allowing analytical SQL. These are input snapshots, not the SQLite
reader schema: DTO splitting, localized overlays, FTS and SQLite-derived views
are not reproduced. Target-language builds are rejected rather than mislabeled.

The native `pdo_duckdb` extension must use DuckDB 2.x. For the tested driver:

```bash
pie install thomas-0816/pdo-duckdb-php:^2-beta
```

For FrankenPHP/Debian/Ubuntu, follow the driver's
[FrankenPHP installation](https://github.com/thomas-0816/pdo-duckdb-php#install-and-setup-with--frankenphp-debianubuntu),
keeping the explicit `^2-beta` constraint when installing with PIE for PHP-ZTS.
Verify in the actual production PHP runtime with `SELECT version()`; the ordinary
CLI PHP and FrankenPHP can have different extension installations. The tested
release is `2.0.0.0-beta2`, which contains DuckDB `v2.0.0`.

```php
$snapshot = $duckDbBuilder->build($input, '/local/collection.duckdb');
$db = new \PDO('duckdb:'.$snapshot['path'], options: [
    \PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only'],
]);
$count = $db->query('SELECT count(*) FROM doc')->fetchColumn();
$statement = $db->prepare('SELECT * FROM doc WHERE id = ?');
$statement->execute([$localId]);
$row = $statement->fetch(\PDO::FETCH_ASSOC);
```

Both `.jsonl` and `.jsonl.gz` already work through `read_ndjson` and
`read_ndjson_objects`; gzip decompression happens inside DuckDB. For example:

```php
$db = new \PDO('duckdb::memory:');
$statement = $db->prepare('SELECT count(*) FROM read_ndjson(?)');
$statement->execute(['/local/objects.jsonl.gz']);
echo $statement->fetchColumn();
```

No driver patch is required for these input formats. In the snapshot builder,
schema aggregation scans every original JSON object before bulk materialization,
so late/sparse fields are not lost through limited schema sampling, and JSON
strings such as identifiers or dates keep their original string types.


## Direct DuckDB reads over S3/HTTPS

`DuckDbFolioReader::open($uri, $s3Configuration)` creates an in-memory DuckDB
connection, loads `httpfs`, and attaches the published snapshot read-only under
the `folio` catalog. The default catalog is switched to `folio`, so ordinary
queries can use `doc`, `article`, `aux.page`, etc. There is no download-to-file,
archive inflation, local Folio restoration, rclone mount, or `harvest:sync` step.
DuckDB fetches blocks with signed HTTP range requests and may cache them in memory.
Local snapshot paths are also supported and opened read-only.

Install DuckDB's `httpfs` extension once in the target PHP runtime (`INSTALL
httpfs`); the reader only loads it and does not install extensions during requests.
For private S3 objects, pass the existing application's S3 endpoint, key, secret
and region explicitly. Credentials are passed as prepared parameters into a
connection-scoped secret; they are not written into the snapshot or a persistent
DuckDB secret store. Public HTTPS snapshots need no S3 configuration.

```php
use Survos\FolioBundle\Dto\DuckDbS3Configuration;

// Inject these values from the application's existing S3 configuration.
$s3 = new DuckDbS3Configuration($endpoint, $accessKey, $secretKey, $region);
$db = $duckDbReader->open(
    's3://survos-platform/folio-archive/example/collection.<revision>.duckdb',
    $s3,
);
$count = $db->query('SELECT count(*) FROM doc')->fetchColumn();
$statement = $db->prepare('SELECT * FROM article WHERE id = ?');
$statement->execute([$localId]);
$row = $statement->fetch(\PDO::FETCH_ASSOC);
```

Use immutable versioned or content-addressed object keys, then announce the new
URI through the owning application's publication metadata. A reader should open
the new URI for the new revision; do not overwrite an object that an attached
connection is still reading. This service opens a known URI and does not replace
catalog discovery or update existing SQLite reader repositories automatically.

RustFS was verified directly at `http://127.0.0.1:9000` with path-style S3 access
and a private test object, using the local RustFS credentials. The initial test
snapshot had 147 documents and 14,767 pages: first attachment 473 ms, then the
count query 4.2 ms. This is an initial observation, not a repeated remote benchmark.
Further WD/RustFS measurements stopped when the WD drive was ejected.
An isolated loopback integration test also checks signed range requests, total
bytes transferred below the whole database size, prepared queries, and read-only
enforcement without accessing external storage.

### Initial local comparison (2026-10-29)

Same explicit inputs on the internal SSD: 147 documents, 13,286 articles and
14,767 pages. Three builds in alternating order; fresh-read medians over five
new connections and warm-query medians over twenty executions. OS caches were
not flushed. The first SQLite build took 4.67 s; subsequent builds took 1.94 s
and 1.90 s. DuckDB builds took 1.08 s, 1.09 s and 0.98 s.

| Measurement | SQLite folio | DuckDB snapshot |
| --- | ---: | ---: |
| Median build | 1.94 s | 1.08 s |
| Working file size | 108.2 MB | 109.9 MB |
| Local open | 0.05–0.11 ms | 6.4–8.9 ms |
| Warm item count | 0.008 ms | 0.213 ms |
| Warm article title lookup | 0.005 ms | 0.469 ms |
| Warm page dimensions grouped by type | 6.98 ms | 0.83 ms |
| Warm article counts grouped by year | 53.64 ms | 0.24 ms |

All four query results were reconciled between engines. These compare the actual
current builders and schemas, not identical storage engines: SQLite performs DTO
projection, page reconciliation, indexes, views, metadata and a bare snapshot,
with gzip archive and FTS disabled. DuckDB stores original JSON plus inferred
columnar tables, without recreating the SQLite reader schema. Year grouping reads
JSON in SQLite and a typed column in DuckDB. Small indexed lookups favor SQLite;
analytical scans favor DuckDB here. These figures do not predict WAN/S3 latency.

The Doctrine DBAL driver can provide framework connections and query builders
over the same native PDO driver, but it does not translate SQLite-specific SQL,
FTS5, generated columns or different table layouts automatically. Reader code
still needs a compatible projection or repository implementation.

### CMA companion validation (2026-10-30)

The normal Harvest rebuild produced 83,718 items (68,778 objects, 9,228 people,
5,712 exhibitions). All values in 19 exported tables matched the SQLite folio,
including 41,478 pages, 56,945 links, 766,089 facet rows, and 29,596 translation
rows. All seven DTO projections matched by item identity. The complete comparison
took 18.15 seconds locally; this is one validation run, not a benchmark.

The collection overview and all 83,718 browsing projections matched for identity,
label, DTO type, year, citation URL, description, page count and location fields.
The existing Harvest overview and people-search pages returned HTTP 200.
The actual Symfony context opened both files with build ID
`fb131fb146031e3a2f44e44b554c2b0a` and returned 9,228 people through PDO.
The ES indexing side effect failed on an existing flood-stage disk watermark;
this did not affect the SQLite build, DuckDB export, or comparisons.
