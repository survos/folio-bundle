# folio_rows: the 2-segment route migration, and the live 500 it is causing

Status: **one live bug + one unfinished migration.** They are related but should be fixed
separately — the bug is a one-line correction, the migration is a design decision.

## 1. The live bug

`/{_locale}/f/{provider}/{dataset}/{coreCode}` — any folio core listing page — returns
**HTTP 500**:

```
Some mandatory parameters are missing ("provider", "dataset") to generate a URL
for route "folio_rows"
in @SurvosFolioBundle/folio/core.html.twig at line 9
```

Reproduce (harvest/zm, any folio with more than one core):

```
https://zm.wip/en/f/iai/885085140/article
```

The cause is visible in the template. It computes the values, explains in a comment why it
has to, and then does not pass them:

```twig
{# folio_rows (Row::API_ROWS) is still {provider}/{dataset}/{coreCode} — its own separate,
   not-yet-done migration (see PhotoGrid's docblock) — so this is the one place in this
   template that still needs the split, purely to feed that API endpoint. #}
{% set parts = folio.code|split('/') %}
{% set provider = parts[0] %}
{% set dataset = parts[1] %}
{% set apiUrl = path('folio_rows', {
    folioCode: folio.code,     ← wrong: this route takes provider + dataset
    coreCode: core.code
}) %}
```

`Row::API_ROWS` is declared with a three-variable URI template:

```php
// src/Entity/Row.php
new GetCollection(
    uriTemplate: '/folios/{provider}/{dataset}/{coreCode}/rows',
    uriVariables: [
        'provider'  => new Link(identifiers: ['provider']),
        'dataset'   => new Link(identifiers: ['dataset']),
        'coreCode'  => new Link(identifiers: ['coreCode']),
    ],
    name: self::API_ROWS,
    ...
)
```

So `$provider` and `$dataset` are set on lines 7–8 and discarded on line 9. The fix:

```twig
{% set apiUrl = path('folio_rows', {
    provider: provider,
    dataset: dataset,
    coreCode: core.code
}) %}
```

Found 2026-09-12 while adding the `iai` provider. It is not specific to that provider —
it affects every core listing page, and predates the change that surfaced it.

## 2. The migration this is a symptom of

`folio.code` is a **single opaque key** (`iai/885085140`, `mus/cazma`,
`cron-america/2022239700`). Most of folio-bundle now treats it that way: `folioCode` is
passed whole to `survos_folio_term_show`, `survos_folio_iiif_manifest`, `FolioItem`, and
the rest. `Row`'s `#[ApiResource]` operations are the holdout, still splitting it into
`{provider}/{dataset}`.

See `src/Twig/Components/PhotoGrid.php`:

> The route this targets (Row::API_ROWS = 'folio_rows') is still 2-segment
> ({provider}/{dataset}/{coreCode}) — folio-bundle is mid-migration toward a single unique
> dataset/slug segment (survos-sites/scanseum#12/#18), but that hasn't reached Row's
> `#[ApiResource]` operations yet.

### Why the split is fragile, not just untidy

`parts[1]` assumes a dataset key is exactly two segments. Nothing enforces that. A key with
one segment silently yields `dataset = null`; a key with three silently **truncates** —
`a/b/c` becomes provider `a`, dataset `b`, and `c` is lost. Neither case errors; both
produce a wrong URL that 404s somewhere else entirely.

So the split is not merely a leftover. It is a lossy parse of a value that is opaque by
design, performed in a template, with no validation.

### What finishing it looks like

1. Change `Row`'s `GetCollection` / `Get` to
   `/folios/{folioCode}/{coreCode}/rows[/{localId}]`, with `folioCode` as the single
   identifier. Note `folioCode` contains a `/`, so the route needs
   `requirements: ['folioCode' => '.+']` or the code needs encoding at the boundary —
   decide which deliberately, because it affects every caller.
2. Update `FolioRowProvider` to resolve a folio from `folioCode` rather than
   `provider` + `dataset`.
3. Update `PhotoGrid` — drop `$provider`/`$dataset`, take `$folioCode`. Its Stimulus
   controller builds page-2+ URLs client-side against the same endpoint, so the JS has to
   move with it.
4. Delete the split from `core.html.twig`. It exists only to feed this route; when the
   route stops needing it, the comment and the three `{% set %}` lines go.
5. Grep for remaining callers before declaring it done:
   `grep -rn "folio_rows\|API_ROWS" --include='*.php' --include='*.twig'`

### Do the one-line fix first

The bug above is live now and the migration is a larger change with a real decision in it
(step 1). Fixing the template argument restores the page immediately and does not make the
migration harder — the three `{% set %}` lines are deleted either way.

## Suggested prompt for whoever picks this up

> In `mono/bu/folio-bundle`, `folio/core.html.twig` line 9 calls `path('folio_rows', …)`
> with `folioCode`, but that route (`Row::API_ROWS`) takes `provider`, `dataset` and
> `coreCode`. The template already computes `provider` and `dataset` on lines 7–8 and then
> ignores them, so every folio core listing page 500s — reproduce at
> `https://zm.wip/en/f/iai/885085140/article`. Fix the argument list first and confirm the
> page renders.
>
> Then finish the migration it is a symptom of: `Row`'s `#[ApiResource]` operations are the
> last place still splitting `folio.code` into `{provider}/{dataset}`, while the rest of the
> bundle passes `folioCode` whole. Move them to `/folios/{folioCode}/{coreCode}/rows`,
> update `FolioRowProvider` and the `PhotoGrid` component (including its Stimulus
> controller, which builds page-2+ URLs client-side), and delete the split from the
> template. `folioCode` contains a slash — decide explicitly between a `.+` route
> requirement and encoding at the boundary, because it changes every caller. Context:
> survos-sites/scanseum#12 / #18, and the docblock on `src/Twig/Components/PhotoGrid.php`.
