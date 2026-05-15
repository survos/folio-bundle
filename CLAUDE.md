# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Bundle Does

`survos/folio-bundle` stores normalized/enriched JSONL rows as portable SQLite files called "folios". It is the database and browsing layer for data that has already been normalized — it is not a raw import or normalization tool. It replaces the useful parts of the old Pixie bundle.

## Key Commands (run from `demo/`)

```bash
# Create or update schema for a folio SQLite file
php bin/console folio:migrate <folioCode>

# Load normalized JSONL rows into a folio core
php bin/console folio:ingest <folioCode> --core=<coreCode> --file=<path/to/file.jsonl>

# Show row counts
php bin/console folio:info <folioCode>

# Browse cores or rows
php bin/console folio:browse <folioCode>
php bin/console folio:browse <folioCode> --core=<coreCode> --limit=10

# Syntax-check all PHP files after edits
find /home/tac/g/sites/mono/bu/folio-bundle/src -name '*.php' | sort | xargs -n1 php -l
```

The demo app is at `demo/`. Composer symlinks the bundle directly from this directory (`vendor/survos/folio-bundle -> ../..`).

## Architecture

### The Folio/Core/Row hierarchy

- **Folio** — one SQLite file, identified by a `folioCode` (e.g. `mus/cleveland`). The SQLite path is `$dbDir/$folioCode.$extension`.
- **Core** — a named collection within a folio (e.g. `obj`, `per`). Composite ID: `folioCode:coreCode`.
- **Row** — one normalized record within a core. Composite ID: `folioCode:coreCode:localId`. Stores `dtoClass`, `dtoData` (DTO-shaped payload), `extras` (non-DTO fields), and optionally `raw`.
- **TermSet / Term** — controlled vocabulary within a folio.
- **RelationType / Relation** — typed many-to-many links between rows in different cores within a folio.

Entity composite IDs use colon-delimited strings (`Core::id()`, `Row::id()` static helpers).

### The connection-switching pattern

All folios share a **single** Doctrine entity manager (`folio` EM), but switch the underlying SQLite file on demand. `FolioConnectionWrapper` extends `Doctrine\DBAL\Connection` and exposes `selectDatabase(string $path)` which closes the current connection and reopens it against the new path.

`FolioService::context(string $folioCode, bool $ensureSchema = false): FolioContext` is the main entry point: it calls `switch()` to point the EM at the right file, optionally runs `FolioSchemaManager::update()`, and returns a `FolioContext` value object holding `{folioCode, path, em}`.

`FolioSchemaManager::update()` uses Doctrine's `SchemaTool::updateSchema()` against all seven folio entity classes to create or migrate the SQLite schema in place.

### Required Doctrine configuration

The bundle requires a dedicated DBAL connection with `wrapper_class: Survos\FolioBundle\DBAL\FolioConnectionWrapper` and a matching ORM entity manager named `folio`. See `docs/configuration.md` for the full YAML. Without the wrapper, `FolioService::switch()` throws immediately.

Bundle config keys (`survos_folio:`): `db_dir` (default `%env(APP_DATA_DIR)%/folio`), `extension` (default `folio.sqlite`), `entity_manager` (default `folio`).

## Planned Changes (from RESUME.md)

The next phase integrates with `survos/data-bundle`:

1. **`FolioService::path()`** must support `/` in folio codes and create nested directories (`dirname($path)`) — e.g. `mus/cleveland` → `$dbDir/mus/cleveland.folio.sqlite`.
2. **`FolioSchemaManager`** needs `updateSql(EntityManagerInterface $em): array` using `SchemaTool::getUpdateSchemaSql($metas, true)` for dry-run support.
3. **`FolioRegistry`** (`src/Service/FolioRegistry.php`) will query `DatasetInfo` rows from the default app EM and resolve core source JSONL files via `DataPaths`.
4. **`FolioMigrateCommand`** will be rewritten to accept an optional dataset arg and `--all`, `--provider`, `--force` options, querying `DatasetInfo` instead of taking a raw file path.
5. **`FolioIngestCommand`** will be rewritten to read `class` from the JSONL payload into `Row::$dtoClass`, split the payload into `dtoData` vs `extras` by reflecting DTO public properties, and use `DatasetInfo` for file resolution.

Folio codes match dataset keys exactly (`mus/cleveland`, `fortepan/hu`, `dc/nv935r28t`). The folio directory (`APP_DATA_DIR/folio/`) lives alongside the data-bundle pipeline tree (`APP_DATA_DIR/work/`).
