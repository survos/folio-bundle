# Structured data (JSON-LD) on the row detail page

The row detail page (`survos_folio_row_show`) publishes a schema.org `@graph` describing the
object it shows. This is what lets a crawler read a folio row as *a photograph from 1961,
held by an institution, depicting a place with these coordinates* rather than as a page of
words.

Off unless the host app installs `survos/schema-org-bundle`. Everything below is skipped
entirely when it isn't — `RowSchemaOrgBuilder` is only registered when the bundle's classes
exist, and `FolioController` takes it as a null-on-invalid argument.

## Turning it on

```bash
composer require survos/schema-org-bundle
```

```yaml
# config/packages/survos_schema_org.yaml
survos_schema_org:
    auto_inject: true
```

`auto_inject` rather than `{{ render_schema_org() }}`, because the row templates are
bundle-owned (`@SurvosFolioBundle/folio/row/*.html.twig`) and extend whatever layout the
request resolved to — there is no single app template to put the Twig call in. A template
that *does* call `render_schema_org()` suppresses the injection for that request, so this
can never produce two `@graph` blocks.

Verify a page with the bundle's own validator (extracts, parses, and checks every JSON-LD
block on a URL):

```bash
bin/console schema:validate https://zm.survos.com/en/f/mus/fortepan/obj/photograph/1
```

## What a row page publishes

For `mus/fortepan` row `photograph/1`, five nodes:

| Node | `@id` | Where it comes from |
|---|---|---|
| `Collection` | `<folio url>#collection` | the folio — `Folio::$label`, its show URL |
| `WebPage` | `<row url>#webpage` | the page itself; `mainEntity` → the item |
| `Photograph` | `<row url>#item` | the DTO (type from its `#[SchemaOrg]`) |
| `ImageObject` | `<row url>#image` | `Row::getThumbnailSource()` |
| `Place` | `<row url>#place` | the DTO's city/state/country + lat/lng |

Plus a `Person` per entry in `$creators` and an `Organization` for `$institution`, both keyed
on a URL derived from the name — so one photographer is one node across the whole graph
rather than one node per mention.

## The split: attributes vs. this bundle

`survos/data-contracts` already annotates its item DTOs:

```php
#[SchemaOrg('Photograph')]
class PhotographDto extends AbstractWorkDto { … }
```

```php
#[SchemaProperty('name')]        public ?string $title = null;
#[SchemaProperty('description')] public ?string $description = null;
#[SchemaProperty('sameAs')]      public ?string $sourceUrl = null;   // AbstractEntityDto
```

Those are inert on their own — data-contracts declares `survos/schema-org-bundle` as a
`suggest`, not a `require`. `SchemaOrgMapper` reads them and produces the typed node with
its declarative scalars filled in; `RowSchemaOrgBuilder` writes everything that needs a
decision. That is the boundary schema-org-bundle documents, and the reason the type lives on
the DTO: **the type is a claim about what the object is, and belongs with the object's
definition, not with the page that renders it.**

`#[SchemaOrg]` is inherited from the nearest ancestor that declares one, so `PostcardDto
extends PhotographDto` is a `Photograph` and `InterviewDto extends AudioDto` is an
`AudioObject` without restating anything.

### Choices made in the builder, and why

- **CreativeWork-only properties are guarded.** `PlaceDto`, `PoiDto` and `EventDto` also
  extend `BaseItemDto`, and a `Place` is not a `CreativeWork` — emitting `creator` or
  `license` on one is invalid, not merely unhelpful. Hence the
  `instanceof CreativeWorkContract` check, and the matching rule in `BaseItemDto` that only
  Thing-level properties get `#[SchemaProperty]` on the base.
- **`name` falls back to `Row::$label`.** Many sources ship no title at all (Fortepan is
  description-only), and `$label` is what the page puts in its `<h1>` — an AI caption, or
  the local id. Without the fallback the node goes out untitled while the page has a title.
- **Rights are split by type.** `license`/`usageInfo` take a URL or CreativeWork, never bare
  text, but `$rightsUri` as harvested is often not a URI ("CC-BY-SA-3.0"). A real URL becomes
  `license`; anything else becomes `conditionsOfAccess`, which is Text-typed and therefore
  true. Mapping licence identifiers to canonical CC deeds belongs in the harvest, where the
  source's own vocabulary is known — not here, inventing a link nobody gave.
- **The image is the raw source URL, not the imgproxy one.** A signed imgproxy URL is a
  rendering detail with an expiry; publishing it as the image's identity hands consumers a
  link that outlives nothing.
- **`Place` is keyed on the row's URL, not the place name.** "Springfield" from two different
  sources is not evidence of the same Springfield. A geonames-backed `@id` would be, and
  `$dto->geonamesId` is the upgrade path when the source provided one.

## Known gaps

- **DTOs with no `#[SchemaOrg]` anywhere in their ancestry** publish the `WebPage` only, and
  no node for the object. Currently: `StoryDto`, `StopDto`, and everything under
  `PhysicalObjectDto` (`ArtifactDto`, `EphemeraDto`, `CoinDto`, `CurrencyDto`). This is
  deliberate — defaulting them to `CreativeWork` would publish a type nobody chose and would
  hide the gap. The fix is one `#[SchemaOrg]` per DTO in data-contracts, which is a
  type-truth decision, not a mechanical one.
- **Language follows the folio, not the URL.** The graph reflects `Row::$dtoData`, so a
  source-language description is published as-is even under an `/en/` locale prefix.
  Translated folio builds (`fortepan.en.folio`) carry translated `dtoData` and come out
  translated; the base folio under a locale prefix does not.
- **No `AudioObject.contentUrl`** for audio/interview rows yet — the node is typed correctly
  but does not point at the media file.
