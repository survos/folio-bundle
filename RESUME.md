# Folio Bundle Resume Notes

## Goal

`folio-bundle` is the fresh replacement for the useful part of Pixie: take normalized/enriched JSONL rows and store them in portable SQLite folio files. Folio should be a database representation and browser/editor layer for already-built data, not a raw import or normalization tool.

`data-bundle` owns dataset discovery and the build tree. Folio must use `DatasetInfo` rows from the app database, not scan `APP_DATA_DIR/work` directly.

## Current Design Decision

Folio databases should live outside the data build tree:

```text
APP_DATA_DIR/
  work/                 # data-bundle pipeline: raw/normalized/enriched/searchable
    mus/cleveland/...
  folio/                # portable SQLite outputs
    mus/cleveland.folio.sqlite
    fortepan/hu.folio.sqlite
    dc/nv935r28t.folio.sqlite
```

Use the dataset key as the folio code:

```text
datasetKey: mus/cleveland
folioCode: mus/cleveland
folio db: APP_DATA_DIR/folio/mus/cleveland.folio.sqlite
```

This means `FolioService::path()` must accept `/` in folio codes and create nested directories with `dirname($path)`, not only the top-level folio directory.

## Data Bundle Change Already Applied

`data-bundle` now has an application-level provider allowlist:

```yaml
survos_data:
  providers: [mus, fortepan, dc]
```

Changed files:

- `/home/tac/g/sites/mono/bu/data-bundle/src/SurvosDataBundle.php`
  - Added config key `providers` as an array of scalar provider codes.
  - Injects `$config['providers']` into `ScanDatasetsCommand` as `$enabledProviders`.

- `/home/tac/g/sites/mono/bu/data-bundle/src/Command/ScanDatasetsCommand.php`
  - Added constructor arg `array $enabledProviders = []`.
  - `listProviderDirs()` now filters to the configured provider list.
  - `--provider` can only narrow within that list; if outside the allowlist, it returns no provider dirs.

Validation already run:

```bash
php -l /home/tac/g/sites/mono/bu/data-bundle/src/SurvosDataBundle.php
php -l /home/tac/g/sites/mono/bu/data-bundle/src/Command/ScanDatasetsCommand.php
```

Both passed.

## Demo App State

Demo lives at:

```text
/home/tac/g/sites/mono/bu/folio-bundle/demo
```

Composer is configured so the demo uses this local bundle on disk:

```text
vendor/survos/folio-bundle -> /home/tac/g/sites/mono/bu/folio-bundle
vendor/survos/field-bundle -> /home/tac/g/sites/mono/bu/field-bundle
vendor/survos/data-contracts -> /home/tac/g/sites/mono/lib/data-contracts
```

`demo/config/packages/survos_data.yaml` currently contains:

```yaml
survos_data:
  providers: [mus, fortepan, dc]
```

The demo `.env` has:

```env
APP_DATA_DIR=%kernel.project_dir%/../data
```

The demo Doctrine config has a dedicated `folio` SQLite entity manager. A bootstrap folio database was created successfully with:

```bash
php bin/console doctrine:schema:update --force --em=folio
```

Target file:

```text
/home/tac/g/sites/mono/bu/folio-bundle/data/folio/_bootstrap.folio.sqlite
```

## Normalized Data Shape

Normalized JSONL rows now start with a `class` field. That field is the DTO class and should be stored in `folio_row.dto_class`.

Example:

```json
{"class":"Survos\\DataContracts\\Dto\\Item\\DrawingDto","id":"obj-1","title":"Study for a Garden","mat":["paper","graphite"]}
```

`Row` already has the needed columns:

- `dtoClass` -> `dto_class`
- `dtoData` -> serialized DTO-shaped data
- `extras` -> non-DTO fields that did not serialize into the DTO shape
- `raw` -> optional raw copy; leave null unless we decide the duplication is useful

This is the key conceptual replacement for Pixie `Core` field arrays. The row shape comes from a PHP DTO class in `data-contracts`, not Pixie-specific YAML/arrays.

## Folio Command Behavior To Implement

Keep migrate and ingest separate.

### `folio:migrate`

Should query `DatasetInfo` from the default app DB.

Expected forms:

```bash
bin/console folio:migrate mus/cleveland
bin/console folio:migrate --provider=mus
bin/console folio:migrate --all
bin/console folio:migrate --all --force
```

Behavior:

1. Select datasets from `DatasetInfo`, not filesystem scanning.
2. Include datasets that have normalized or enriched JSONL.
3. Resolve folio path from `datasetKey`.
4. Open/switch to that SQLite file with `FolioService`.
5. Compare schema against Folio Doctrine entities.
6. Without `--force`, report out-of-sync SQL and return failure if any dataset is out of sync.
7. With `--force`, run schema update and persist/update the `Folio` row.

### `folio:ingest`

Should also query `DatasetInfo`.

Expected forms:

```bash
bin/console folio:ingest mus/cleveland
bin/console folio:ingest --provider=mus
bin/console folio:ingest --all
bin/console folio:ingest mus/cleveland --core=obj
```

Behavior:

1. Select datasets from `DatasetInfo`.
2. Prefer enriched JSONL when present, otherwise normalized JSONL.
3. For each selected core, load JSONL rows.
4. For each row:
   - read `class` into `Row::$dtoClass`;
   - remove `class` from payload;
   - split payload into `dtoData` vs `extras` by reflecting public properties on the DTO class, including inherited public properties;
   - persist `Row` with `localId` from `id`, `@id`, or `_id`;
   - label fallback should be `label`, then `title`, then `localId`.
5. Keep `Folio::$datasetKey`, `Folio::$label`, `Core::$rowCount`, and `Folio::$rowCount` up to date.

## Folio Services To Add/Finish

Add a `FolioRegistry` service, likely in:

```text
src/Service/FolioRegistry.php
```

Responsibilities:

- Inject default app `EntityManagerInterface` and `Survos\DataBundle\Service\DataPaths`.
- Query `DatasetInfo` rows.
- Normalize CLI refs like `mus_cleveland` to `mus/cleveland` only as a convenience.
- Return only datasets with a usable source file.
- Resolve core source files:
  - `DataPaths::enrichFile($datasetKey, $core)` first;
  - `DatasetInfo::$normalizedPath` for `obj` fallback;
  - `DataPaths::stageDir($datasetKey, 'normalized') . "/$core.jsonl"` for non-obj normalized cores.

Update `SurvosFolioBundle` to register `FolioRegistry`.

Consider adding `survos/data-bundle` as a hard `require` in `folio-bundle/composer.json`; given the design now depends on `DatasetInfo`, Folio is no longer just standalone SQLite browsing.

## Interrupted Patch Warning

An attempted service patch was interrupted and should not be trusted. Before continuing, inspect these files directly:

```bash
git -C /home/tac/g/sites/mono/bu/folio-bundle diff -- src/Service/FolioService.php src/Service/FolioSchemaManager.php src/SurvosFolioBundle.php composer.json
```

At the time this note was written, the tracked diff for those files appeared empty, but confirm before editing.

## Next Safe Implementation Steps

1. Update `FolioService::path()` to create nested provider dirs.
2. Update `FolioSchemaManager` with `updateSql(EntityManagerInterface $em): array` using `SchemaTool::getUpdateSchemaSql($metas, true)`.
3. Add/register `FolioRegistry`.
4. Rewrite `FolioMigrateCommand` with `dataset` optional arg, `--all`, `--provider`, `--force`.
5. Rewrite `FolioIngestCommand` with registry selection and `class` -> `dtoClass` handling.
6. Run:

```bash
find /home/tac/g/sites/mono/bu/folio-bundle/src -name '*.php' -print | sort | xargs -n1 php -l
```

7. In demo, run:

```bash
php bin/console debug:config survos_data
php bin/console data:scan-datasets --provider=mus --limit=1
php bin/console folio:migrate --provider=mus --force
php bin/console folio:ingest --provider=mus --core=obj
```

The exact data paths may need adjustment depending on what datasets are present under `APP_DATA_DIR/work`.
