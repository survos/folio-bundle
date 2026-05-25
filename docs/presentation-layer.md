# Folios as a Presentation Layer

Folio is not only a search archive for individual objects. It can also be the publication format for an institution-facing narrative site: collections, exhibitions, themes, essays, object groupings, timelines, landing pages, and interpretive paths.

This is closer to Omeka S `/s/{site}` pages or CollectiveAccess Pawtucket than to a search engine. Search is useful, but it is not the whole presentation layer.

## Core Idea

The operational system builds and manages data. The folio packages a stable, self-describing publication snapshot. A presentation site reads that folio and renders public-facing narratives from it.

The institution can publish a folio that contains:

- object rows;
- collection rows;
- person/place/organization rows;
- terms and term sets;
- relations between rows;
- observed schema metadata;
- generated docs;
- curated page/navigation data;
- optional full-text and RAG indexes.

The viewer does not need to be the same app that harvested or normalized the data. It can be a lightweight Symfony site, a static generator, a Python app, a local kiosk, or a partner-hosted site.

## Why This Matters

Museum and archive presentation layers need stability and editorial shape.

The public site should not be tightly coupled to:

- harvest jobs;
- import queues;
- admin workflows;
- schema churn;
- source-provider outages;
- search-index rebuilds;
- AI enrichment runs.

A folio gives the presentation layer a stable publication package. Curators and developers can decide when to publish a new snapshot. The site can keep serving the previous snapshot until the new one is ready.

## Beyond Object Browse

A search/browse interface answers: “What objects match this query?”

An institutional presentation layer answers broader questions:

- What collections does this institution present publicly?
- What stories connect these objects?
- Which objects belong to this exhibition or theme?
- How should a visitor move through this material?
- What essays, labels, biographies, places, and terms explain the collection?
- How can a teacher, researcher, or visitor follow a curated path?

Folio should support both, but the presentation-layer use case needs more than `item` rows.

## Suggested Folio Content Model

The existing folio model already points in this direction:

- `core`: logical groups such as `obj`, `coll`, `per`, `place`, `page`, `exhibit`, `section`.
- `item`: normalized rows for each core.
- `relation_type`: relationship definitions.
- `relation`: links between rows.
- `term_set` and `term`: controlled values, navigation terms, facets, and vocabularies.
- `schema_table` and `schema_property`: observed schema for each row group.
- `docs`: human/agent/machine-readable documentation.

For narrative presentation, a folio may include cores such as:

| Core | Purpose |
| --- | --- |
| `obj` | collection objects/items |
| `coll` | collections or item sets |
| `page` | site pages, essays, landing pages |
| `exhibit` | exhibitions or curated experiences |
| `section` | ordered sections within a page/exhibition |
| `per` | people and organizations |
| `place` | places and geographies |
| `media` | media records when separate from objects |
| `nav` | navigation/menu definitions |

The exact cores are data-driven. A consumer should read `core`, `schema_table`, and `docs` to discover what a folio contains.

## Narrative Pages

A folio-backed presentation site can render narrative pages from rows and relations.

For example, a `page` row might contain:

```json
{
  "id": "immigration-story",
  "title": "Arriving in the City",
  "slug": "arriving-in-the-city",
  "summary": "A story about migration, work, and family photographs.",
  "body": "Markdown or structured blocks...",
  "template": "essay"
}
```

Relations connect that page to objects, people, places, terms, and sections:

```text
page:immigration-story --features--> obj:ark:/12345/abc
page:immigration-story --mentions--> place:lower-east-side
page:immigration-story --has-section--> section:immigration-story/01
```

The presentation layer can render the page without knowing the original CMS or admin workflow.

## Collections and Exhibitions

A collection or exhibition can be represented as rows plus ordered relations.

Example:

```text
exhibit:postcards-home
  title: "Postcards Home"
  summary: "Messages, travel, and memory in early twentieth-century postcards."

relations:
  exhibit:postcards-home --has-section--> section:postcards-home/intro
  exhibit:postcards-home --features--> obj:ark:/12345/postcard-001
  exhibit:postcards-home --features--> obj:ark:/12345/postcard-002
```

This gives Pawtucket/Omeka-style public browsing:

- exhibition landing pages;
- curated object lists;
- section-by-section narratives;
- related people/places/subjects;
- object pages in context;
- citation/export links.

## Navigation

Navigation can also be data in the folio.

A `nav` core or docs row can define menus:

```json
{
  "main": [
    {"label": "Collections", "target": "page:collections"},
    {"label": "Exhibitions", "target": "page:exhibitions"},
    {"label": "Places", "target": "page:places"}
  ]
}
```

This lets the presentation app be generic. It renders menus and pages from the folio rather than hard-coding a specific institution's structure.

## Full-Text Search as a Supporting Feature

Full-text search still matters, but it should support the narrative site rather than define it.

FTS can power:

- site-wide search;
- “search within this exhibition”;
- related objects for a page;
- admin/research discovery;
- fallback navigation when the visitor knows what they want.

Example:

```sql
select d.local_id, d.label, d.dto_type, bm25(item_fts) as score
from item d
join item_fts f on f.rowid = d.rowid
where item_fts match :query
order by score
limit 25;
```

But the public homepage, exhibition pages, and collection narratives should be driven by page/collection/exhibition data and relations, not only by search results.

## RAG for Interpretive Sites

RAG is useful for narrative presentation when it is grounded in the folio.

A visitor might ask:

- “What objects in this exhibition relate to immigration?”
- “Who are the people mentioned in this story?”
- “Summarize this collection for a high school class.”
- “Which objects are connected to this place?”

The RAG system can retrieve from:

- page bodies;
- exhibit summaries;
- object descriptions;
- relation context;
- term descriptions;
- `docs` rows explaining the archive.

Because the folio is self-contained, answers can cite rows and pages from the same publication snapshot.

## Example: Small Institutional Site

A local history society publishes one folio:

```text
society/main.folio.sqlite
```

It contains:

- `page`: home, about, visiting, research guide;
- `coll`: oral histories, postcards, city directories;
- `exhibit`: “Main Street Then and Now”;
- `obj`: digitized objects;
- `per`: people mentioned in records;
- `place`: local neighborhoods and buildings;
- `term_set`: subjects, formats, eras;
- `relation`: page/exhibit/object/person/place links.

A generic folio viewer renders:

```text
/s/main
/s/main/exhibitions/main-street
/s/main/collections/postcards
/s/main/places/train-station
/s/main/item/ark:/...
```

The society can rebuild the folio monthly. The public site stays stable between releases.

## Example: Traveling Exhibition

A museum creates a folio for a traveling exhibition. The file includes the narrative pages, selected objects, captions, rights notes, translations, and search index.

Each host venue can run the same lightweight presentation viewer. The exhibition data is versioned as a folio file, not copied into each venue's operational database.

## Example: Classroom Edition

A teacher-facing site can use a curated folio containing only selected objects, simplified descriptions, lesson pages, and glossary terms. The source institution's full database remains separate.

The presentation layer is smaller, safer, and easier to distribute.

## Relationship to Omeka and Pawtucket

Omeka S `/s/` sites and CollectiveAccess Pawtucket both separate public presentation from back-office collection management. Folio can play a similar role as a portable publication package.

The difference is that folio is file-based and self-describing:

- the data travels as SQLite;
- schema metadata travels with it;
- docs travel with it;
- search indexes can be rebuilt;
- consumers can be PHP, Python, static, desktop, or AI-assisted.

This does not replace full systems like Omeka or CollectiveAccess. It provides a stable archive/presentation boundary that can feed similar public experiences.

## Design Principle

The folio presentation layer should start with narrative structure:

1. site/pages/navigation;
2. collections/exhibitions/themes;
3. relations between pages and rows;
4. object detail pages;
5. search and RAG as supporting discovery tools.

That keeps folio from becoming “just another object search index.” The search engine helps visitors find things; the folio-backed presentation layer helps institutions tell stories.
