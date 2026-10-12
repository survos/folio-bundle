# Folio bundle

Folio stores explicit JSONL inputs as portable SQLite databases and provides reader,
archive, search and metadata services. It must not depend on DatasetBundle or Harvest.

Read [the build pipeline](docs/folio-build-pipeline.md) before changing production
code. Build through `FolioBuilder` with `FolioBuildInput`; never introduce dataset
lookup or pipeline-stage discovery into this bundle. Keep dataset orchestration
and artifact registration in the owning application. Do not recreate removed CLI
shortcuts under a different name or bundle.

Useful commands: `folio:pull`, `folio:info`, `folio:browse`,
`folio:migrate --file=<existing.folio>`, `folio:validate`, and search/index tools.
The removed `folio:build`, `folio:archive`, `folio:translate`, `folio:ingest` and
registry-wide migration paths are not supported workflows.

Preserve atomic working-file publication, human-owned metadata, row/page/claim
relationships, translations, and archives when changing the build service. Failure
must not replace the current live working folio. Test both reader use without a
registry and the producer application's integration.
