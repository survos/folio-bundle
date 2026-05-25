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
