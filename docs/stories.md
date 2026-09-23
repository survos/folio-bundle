# Stories

Design, 2026-09-23. No code yet. Read with [bookmarks.md](bookmarks.md), which ships the half of
this that already exists.

A story is an ordered, authored sequence of saved rows: photographs, newspaper articles, ads,
museum objects, audio — written *about*, not just listed. 64 Parishes' "convict leasing" entry,
Baltimore Heritage's "One Building, Three Stories" tour and Fortepan's exhibits are all the same
artifact.

## Three existing implementations, and what each proves

**KronoFoto** (`~/sites/kronofoto`, Django, read 2026-09-23) has the most complete model, in four
entities:

| entity | holds |
|---|---|
| `Collection` | a user's saved photos; `visibility` is Private / Unlisted / Public. Also the **picking scope**: `Card.photo_choices()` restricts a card's photo to the exhibit's collection "because it would be difficult for Users to find the photo they want without some filtering" |
| `Exhibit` | `name`, `title`, `description`, `smalltext`, cover `photo`, `owner`, `credits`, and the `Collection` it draws from |
| `Card` | **the connector**: `order`, its own `title`/`description`/`smalltext`, a `photo`, a `card_type` (TEXT_ONLY, FULL, LEFT, RIGHT) and a `fill_style` (COVER, CONTAIN) |
| `Figure` | several photos side by side *within* one card, each with `caption` and `order` |

Two things worth taking from it verbatim:

- **A card is an authored unit, not a position.** Prose, layout and item travel together, and
  TEXT_ONLY is a legitimate card — that is how an exhibit gets section headings and transitions
  rather than a wall of images.
- **There is no publish flag.** An `Exhibit` has an owner and a URL; its menu is View, Share (copy
  link) and Embed. Publication is the URL existing, and privacy lives on the collection.

**Curatescape** (mirrored: `curatescape/baltimore`, 31 tours) proves the reading side: a tour is a
public page, no login anywhere. Its raw `tours.jsonl` carries `title`, `description`,
`postscript_text`, `tour_img` and an ordered `items` array (id, title, lat/lng, thumbnail). But a
stop is only a pointer — no per-stop prose — so it is a weaker Card.

**This bundle** ships `Bookmark` + `Folder` (see bookmarks.md): a bookmark is host-owned, points at
any row in any folio by `provider`/`dataset`/`coreCode`/`localId`/`dtoType`, and `FolderVisibility`
is already `public` / `unlisted` / `private` — the same three states as KronoFoto's collection.

## What is missing

| KronoFoto | here | gap |
|---|---|---|
| Collection | `Folder` + `Bookmark` | — exists |
| Exhibit | — | `Story`: title, description, cover, credits, owner, folder |
| Card | — | `Card`: order, prose, layout, one bookmark |
| Figure | — | several bookmarks in one card, captioned |

**A card points at a bookmark, not at a row.** That is the one place to diverge from KronoFoto,
whose `Card.photo` is a foreign key to `Photo`. A bookmark is already cross-folio, so a single
story can hold a Fortepan photograph, a RappNews article, the ad beside it, a museum object and an
audio clip — with no schema claiming they are the same kind of thing. It also keeps the folder's
role as picking scope, exactly as `photo_choices()` does.

Follow bookmarks.md's constraint: these are **host-owned** entities in the host's `default` entity
manager (a real `User` relation), mapped superclasses here, concrete classes in the app. They can
never hold an ORM relation to folio content, which lives in the per-folio SQLite database swapped
at runtime.

## Authoring is gated; reading is not

- **Authoring**: `ROLE_USER`, as zm's `BookmarkController` already requires class-wide, plus a
  `stories` entry in the `beta_features` setting — the same gate `RowMenu` uses for OCR and
  Handwriting (`'dev' === $environment || in_array('ocr', $betaFeatures)`).
- **Reading**: a public route for a story whose folder is `public` or `unlisted`. This is what
  makes it a story rather than a bookmark list, and today it does not exist — `FolderVisibility` is
  declared but nothing serves a folder to an anonymous visitor.

## The import test: Baltimore's 31 tours — and the trap they exposed

Curatescape is the test data, and it already round-trips: harvest's `CuratescapeProvider` writes
`story.jsonl` (story-contract v1), `stop.jsonl` (one `ContentType::STOP` row per narrative unit) and
a real `has_stop` link per stop, **with the ordinal on the link, not the stop** — ordering is a
property of the relationship. `~/sites/rut/docs/PLAN-folio-data-layer.md` has the design and a
`link_has_stop` view per link type.

It was broken from 2026-09-07 to 2026-09-23 (fixed in harvest 05b7b84), and the cause is worth
knowing before adding a second producer of relations:

- **`norm/link.jsonl` has more than one producer, and they all truncate it.** data-bundle's
  `RelationCollector` opens it in `'w'` (and rewrites `linkType.jsonl` wholesale) while the story
  pass wrote the same two files. Last writer won, so the 391 `has_stop` links vanished and the
  tours reached the folio as a bare `stopCount`. The fix merges: rewrite both files as *(everything
  that is not mine) + (mine)*, which is idempotent.
- **`RelationCollector` runs on every convert, including `dataset:enrich`'s norm→_folio pass**,
  which writes back into the *normalize* directory. A producer that runs only on raw→normalize is
  therefore erased one stage later.
- **Stage work belongs on the transition, not in a hand-run command.** The story pass was
  `provider:curatescape:story-normalize`, exactly the footgun `harvest/docs/deprecated.md` warns
  about; it now listens on `ImportConvertFinishedEvent` at priority -100 (after
  `RelationExtractorListener`), the same hook `WikibaseProvider::writeLinks()` uses.

So the story/stop/link layer exists and works. What this document proposes sits *above* it:
user-authored stories, where the cards are written by a person rather than derived from someone
else's tour.

Still worth checking: musdig's event graph has the same shape of loss (`docs/musdig.md:46`, and the
2026-09-20 catalog handoff §2c) — intact through normalization, absent from the folio.

## Open questions

- **Does a story need edges between cards**, beyond order? 64 Parishes and Curatescape both get by
  without them. "This ad ran beside that article" is a relation between *rows*, which is what the
  `link` table is for — probably not the story's job.
- **Where does a story live for an anonymous reader** — the host app (zm, openfoto, ink), or a
  shared route in this bundle? Bookmarks chose host-owned entities with bundle-side services;
  stories probably follow.
- **Embed.** KronoFoto's exhibit menu has it and it is why exhibits travel. Out of scope for a
  first cut, but the public route should not make it hard later.
