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

Commands:

```bash
bin/console folio:migrate cleveland
bin/console folio:ingest cleveland --core=obj --file=$APP_DATA_DIR/work/cleveland/normalized/obj.jsonl
bin/console folio:browse cleveland --core=obj --limit=10
```
