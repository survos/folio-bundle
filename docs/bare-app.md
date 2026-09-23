# A reading app without dataset-bundle

Status: 2026-09-23. `survos/dataset-bundle` is a **suggest** of this bundle, not a require.

## Why

An app that displays published folios needs to resolve folio paths and render rows. It does not
need the production registry: a second Doctrine connection, `DatasetInfo`/`Artifact` entities,
their repositories and API Platform resources, plus a `LogicException` at boot unless the app pins
`default_connection` and `default_entity_manager` so that second connection doesn't hijack the
default.

Until now folio-bundle required it anyway, inherited from the 2026-05-21 `data-bundle` →
`dataset-bundle` rename rather than chosen. The cost was real: fotostory carried the whole registry
to read **one field** (`DatasetInfo::$country`), and on a machine without `APP_DATA_DIR/datasets.db`
— any machine but the one that builds folios — that read silently returned null instead of failing.

Production (harvest builds folios, zm/museado.org publishes them) still installs dataset-bundle.
That is where dataset knowledge belongs.

## What a bare app gets

Everything on the reading path: `FolioService`, `FolioRegistry` (minus `datasets()`), the folio
routes and controllers, search, gallery, chat, bookmarks, sitemaps of what it holds, and the
commands to get folios and look at them — `folio:pull`, `folio:info`, `folio:browse`,
`folio:migrate`, `folio:fts:rebuild`, `folio:restore`.

## What it does not get, and what to use instead

| Not registered | Why | Instead |
|---|---|---|
| `folio:build`, `folio:archive`, `folio:translate`, `folio:validate` | they write the registry | build in harvest, publish, pull the result |
| `FolioCollectionController` (the `/folios` index) | browses `Artifact` rows; its action type-hints `ArtifactRepository`, which the controller-argument pass reflects at compile time | the hub's catalog over HTTP (below) |
| `BuildFolioRequestedListener` | a bare `#[AsEventListener]`, so Symfony reflects `__invoke(BuildFolioRequestedEvent)` | nothing dispatches it in a reader |
| `FolioSitemapRegistry` | enumerates published `Artifact` rows | your own sitemap over the folios you hold |
| `FolioRegistry::datasets()` | needs the registry EM | throws `LogicException` with this same advice |

The check is whether the app registered the `survos_dataset` extension — not whether the classes are
on the autoloader. Dropping the bundle from `config/bundles.php` leaves it in `vendor/` until the
next `composer update`, and during that window its classes exist while its services do not.

Both listener cases are compile-time failures, not runtime ones: registering a service whose
signature names a missing class takes the entire container down, and then no page renders at all.
That is why they are removed rather than left to fail late.

## Configuration

Set `data_dir` so folio paths resolve. Same value and default as `survos_dataset.data_dir`; ignored
when dataset-bundle is installed, because then it registers `DataPaths` itself from its own config.

```yaml
# config/packages/survos_folio.yaml
survos_folio:
    data_dir: '%env(APP_DATA_DIR)%'   # the default; set it explicitly if your app's tree differs
    folio_server: 'https://museado.org'
```

`DataPaths` and the `Stage` enum now live in `survos/data-contracts` (a plain library, no Doctrine),
which this bundle already requires. The old names still work — `Survos\DatasetBundle\Service\DataPaths`
and `Survos\DatasetBundle\Enum\Stage` are aliases to the moved classes, so nothing had to migrate in
lockstep — but they are deprecated and will show up in the app's deprecation report. New code should
import `Survos\DataContracts\Path\DataPaths` and `Survos\DataContracts\Path\Stage`.

## How to access dataset information

The hub publishes it. Ask it over HTTP instead of keeping a registry:

```bash
# Every published folio: datasetKey, provider, code, title, description, rowCount, contentType,
# sizeBytes, checksum, updatedAt, downloadUrl.
curl https://museado.org/folio/list.json

# The richer per-dataset record: locale, country, marking, objCount/normalizedCount, targetLocales,
# artifacts. Filterable, e.g. ?aggregator=loc or ?datasetKey=loc/voices-remembering-slavery.
curl -H 'Accept: application/json' https://museado.org/api/dataset_infos?aggregator=loc
```

Then pull what you want. `folio:pull --api` reads that catalog, downloads each archive, and inflates
it locally — no registry involved:

```bash
bin/console folio:pull --dataset=loc/voices-remembering-slavery --api=https://museado.org
bin/console folio:pull --provider=loc --api=https://museado.org
```

`folio:pull` records what it pulled into the registry **when one is present**, and skips that step
silently when it is not (`FolioPullCommand::doRegisterRestoredFolio()` early-returns on a null EM).
The pull itself is identical either way.

A note on picking datasets by tag: datasets carry editorial tags (`newspaper-source`,
`oral-history`) that folio sets select on, and `/folio/list.json` does not currently publish them —
so tag-driven sets still need the local registry. Publishing `tags` on the catalog is what would
close that gap.

## Adding dataset-bundle back

Install it, enable it in `config/bundles.php`, and the producer services register themselves again —
the bundle checks at compile time whether this app registered the `survos_dataset` extension, so
there is no flag to flip:

```bash
composer require survos/dataset-bundle
```

`tests/Bundle/DatasetRegistryOptionalTest.php` pins both directions, so a future hard dependency on
the registry fails the suite instead of quietly reaching every app that installs this bundle.
