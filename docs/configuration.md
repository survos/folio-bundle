# Folio Bundle Configuration

Folio needs a dedicated SQLite DBAL connection and ORM entity manager. The wrapper is required because `FolioService::context($folioCode)` switches the same entity manager to the selected folio file before reads.

This is not optional: the bundle injects `doctrine.orm.folio_entity_manager` by default. If the host app enables `SurvosFolioBundle` without defining an ORM entity manager named `folio`, container compilation fails with a missing `doctrine.orm.folio_entity_manager` service. To use a different name, set `survos_folio.entity_manager` to the same name you configure under `doctrine.orm.entity_managers`.

```yaml
doctrine:
  dbal:
    connections:
      folio:
        driver: pdo_sqlite
        path: '%env(APP_DATA_DIR)%/folio/_bootstrap.folio.sqlite'
        wrapper_class: Survos\FolioBundle\DBAL\FolioConnectionWrapper
  orm:
    entity_managers:
      folio:
        connection: folio
        mappings:
          SurvosFolioBundle:
            is_bundle: false
            type: attribute
            dir: '%kernel.project_dir%/vendor/survos/folio-bundle/src/Entity'
            prefix: 'Survos\FolioBundle\Entity'
```

```yaml
survos_folio:
  db_dir: '%env(APP_DATA_DIR)%/folio'
  extension: folio.sqlite
  entity_manager: folio
```

## Routes

Routes are registered by kit-bundle's `BundleRouteLoader` (via the `HasConfigurableRoutes`
trait), **not** by a yaml import. The loader is chained onto `router.resource` and adds the
bundle's routes *after* everything in the app's `routes.yaml` — so re-importing the bundle's
controller directory from `routes.yaml` with a `prefix:` silently does nothing (same route
names, clobbered by the loader). Use the bundle config instead:

```yaml
survos_folio:
  route_prefix: /folio        # default; zm uses /f
  # Folio pages are shareable/bookmarkable — bake the locale into the URL
  # (/{_locale}/folio/...). Without this, navigating from a localized app page to a
  # folio route resets the request locale to kernel.default_locale (core
  # LocaleListener falls back whenever the matched route has no _locale).
  locale_prefix: true         # zm and openfoto both enable this
  routes_enabled: true        # false = manage the bundle's routes yourself
```

With `locale_prefix: true` (and >1 `framework.enabled_locales`), paths become
`/{_locale}/folio/...` with `_locale` defaulting to `kernel.default_locale` and constrained
to `enabled_locales`. Old unprefixed URLs 404 — add app-level redirects if needed. Note:
FOSJsRouting-generated URLs (`expose: true` routes) fill `_locale` with the default unless
the caller passes it — harmless for data endpoints (map.geojson), matters for navigation.

## Commands

Dataset keys are opaque provider/dataset identifiers. `harvest/...`, `md/...`, `dc/...`, and legacy keys such as `mus/...` should all be treated as data identifiers, not application names.

```bash
bin/console folio:migrate harvest/cleveland --force
bin/console folio:ingest harvest/cleveland --core=obj
bin/console folio:browse harvest/cleveland --core=obj --limit=10
bin/console folio:archive harvest/cleveland
bin/console folio:restore var/data/folio/harvest/cleveland.folio.sqlite.gz harvest/cleveland --force
```

`folio:ingest` reads datasets from `DatasetInfo` through `FolioRegistry`; it does not scan the work tree directly. It prefers enriched JSONL when present and falls back to normalized JSONL.

## Archive Metadata

After ingest, folio prepares the archive metadata:

1. snapshot observed schema and field stats into `schema_table` / `schema_property`;
2. generate lightweight `dto_*` SQLite views;
3. generate JSON/Markdown rows in `docs`;
4. rebuild FTS5 search indexes.

See `archive-metadata.md` for the archive contract.

## Bookmarks

`Bookmark`/`Folder` are the bundle's first entities meant to be mapped into
the *host app's* `default` entity manager rather than the `folio` EM above —
see `bookmarks.md` for the MappedSuperclass pattern, why it's structured
that way, and the `bookmark_class`/`folder_class` config keys.
