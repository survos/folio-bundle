# Dfolio integration closeout — 2026-11-01

Audited `72e7f0c4c` against main rather than cherry-picking the whole commit.
The Panorama vocabulary, local dfolio locator, file-extension helper, reader
support, storage browser allowlist, BIGINT file size, ETag and storage-class
columns had already landed in later commits (including `0336100a4`, `216f36005`
and `da6074e89`).

This restoration adds the missing dfolio builder/preparer defaults and temporary
file names, ES discovery with legacy DuckDB fallback, storage labels and endpoint
presentation, breadcrumbs and natural directory ordering, metadata display
cleanup, and listing metadata assignment. It restores the associated tests and
documentation. Existing listing-only scan behavior and pinned newspaper `.rend`
publication are retained. The ES parity fixture now publishes a local rendition
and uses non-issue links for its additional relationship-ordering checks.

Validation: 48 focused tests, 225 assertions, covering DuckDB build/export/read,
view/search, ES parity, rendition round trips, storage metadata/native directory
scans, and Panorama link contracts. Tested with PHP 8.5.12 and Harvest's installed
dependencies while explicitly loading these bundles from the isolated checkout.

No artifacts were rebuilt, no consumers started, and no push or deployment was
performed. Application deployments must retain migrations for the storage node
ETag/storage-class columns and BIGINT size; those mappings predate this restoration.
The original branch commit need not be cherry-picked again: its behavior is now
represented by the existing main history plus this restoration.
