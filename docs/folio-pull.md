# Pulling a folio

`folio:pull` downloads a reader `.dfolio` by default. It does not download,
expand, or rebuild a SQLite archive unless `--archive` is supplied.

```bash
php bin/console folio:pull --dataset=news/rappnews4909
php bin/console folio:pull --provider=news
php bin/console folio:pull --all
php bin/console folio:pull --dataset=news/rappnews4909 --force
php bin/console folio:pull --dataset=news/rappnews4909 --archive
```

Without a selection, the command lists the available source-language dfolios.
Harvest's configured dataset API is the authority: the command refreshes its
catalog and selects the published artifact URI and checksum. It does not discover
datasets by listing an S3 bucket. A catalog failure fails the pull.

## Storage selection

Configure these Flysystem services, each exposing the bucket root or an equivalent
local directory:

- `local_folio_dfolio.storage` (optional): preferred local source.
- `folio_dfolio.storage`: published remote source.

For a published `s3://survos-platform/dfolio/news/rappnews4909.dfolio`, the storage
key is `dfolio/news/rappnews4909.dfolio`. Both adapters must represent the published
bucket's contents; do not configure an additional `dfolio` prefix on the adapter.
Either adapter may use a local filesystem or S3. The pull service depends only on
Flysystem, not the AWS SDK. S3 adapters require `league/flysystem-aws-s3-v3` in the
consuming application; local adapters do not.

Ink configures the remote adapter from `S3_ENDPOINT`, `S3_REGION`,
`AWS_S3_ACCESS_ID`, `AWS_S3_ACCESS_SECRET`, and `FOLIO_S3_BUCKET`. Its dev-only local
adapter uses `LOCAL_S3_ENDPOINT`, `LOCAL_S3_REGION`, `LOCAL_S3_ACCESS_ID`, and
`LOCAL_S3_ACCESS_SECRET`, with the same bucket. Put credentials in `.env.local` or
the deployment environment. To use a local directory instead, replace the
adapter configuration with `local: { directory: /path/to/bucket-root }`.

An absent local object falls back to remote storage. When Harvest supplies a
checksum, a stale local copy also falls back. Authentication failures, unavailable
storage, or interrupted transfers fail visibly; they are not treated as absence.

## Installation and verification

The destination is `DataPaths::folioFile(datasetKey, 'dfolio')`, normally
`APP_DATA_DIR/folio/<provider>/<code>.dfolio`. An existing destination is skipped
unless `--force` is supplied. Downloads stream to a unique staging file in the
destination directory. Size, Harvest's SHA-256 checksum when supplied, DuckDB
readability, and the single `_folio_build` identity are checked before atomic
replacement. A failed transfer keeps the previous file and removes its staging
file. Concurrent source replacement is detected by the published checksum when
available. This command does not publish, write to source storage, or update
Harvest's registry. It downloads the dfolio itself, not rendition sidecars.

## Explicit archives

`--archive` retains the existing archive API, `folio_archive.storage`, and Hugging
Face download/restore paths. Archive-specific `--api`, `--storage`, `--hf`, and
`--fts` options require `--archive`:

```bash
php bin/console folio:pull --dataset=loc/example --archive --api=https://harvest.example
php bin/console folio:pull --dataset=loc/example --archive --storage
```

Apps and scripts that previously relied on the implicit SQLite restore must add
`--archive`. Pulling a dfolio is acquisition only; rebuilding projections and
indexing are separate operations.

The existing set-sync workflow explicitly requests archives because its availability
check still targets SQLite folios. Its behavior is preserved.
