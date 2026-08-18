<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Spatie\SchemaOrg\Collection;
use Spatie\SchemaOrg\Contracts\CreativeWorkContract;
use Spatie\SchemaOrg\ImageObject;
use Spatie\SchemaOrg\Organization;
use Spatie\SchemaOrg\Person;
use Spatie\SchemaOrg\Place;
use Spatie\SchemaOrg\Schema;
use Spatie\SchemaOrg\WebPage;
use Survos\DataContracts\Dto\Item\BaseItemDto;
use Survos\FolioBundle\Entity\Row;
use Survos\SchemaOrgBundle\Graph\SchemaOrgGraph;
use Survos\SchemaOrgBundle\Mapping\SchemaOrgMapper;
use Survos\SchemaOrgBundle\Mapping\SchemaOrgMetadataFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Publishes JSON-LD for a single folio row's detail page.
 *
 * Registered only when survos/schema-org-bundle is installed (see SurvosFolioBundle),
 * which is also what makes the #[SchemaOrg]/#[SchemaProperty] attributes already carried
 * by survos/data-contracts' item DTOs do anything — they are inert without it, which is
 * exactly how data-contracts declares the dependency (a `suggest`, not a `require`).
 *
 * The split follows the bundle's own documented boundary: the DTO's attributes map the
 * declarative scalars (type, name, description, sameAs), and everything that needs a
 * decision — which node is the page's main entity, who the creators are, what the
 * institution means, how the folio relates to the row — is written here.
 *
 * Why this lives in the bundle rather than in each app: the row detail page is
 * bundle-owned (@SurvosFolioBundle/folio/row/*.html.twig, rendered by
 * FolioController::rowShow), so an app cannot add structured data to it without
 * overriding a template it doesn't own. Every folio app — zm, openfoto, md — gets the
 * same markup from one place, and opts in simply by requiring the bundle.
 */
final readonly class RowSchemaOrgBuilder
{
    public function __construct(
        private SchemaOrgGraph $schemaOrg,
        private SchemaOrgMapper $mapper,
        private SchemaOrgMetadataFactory $metadata,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Contributes the row page's nodes to the request graph.
     *
     * Nothing is rendered here — the app decides how the graph reaches the page, either
     * with `{{ render_schema_org() }}` in a layout it owns or with
     * `survos_schema_org.auto_inject: true`. The row templates extend the *host app's*
     * layout, so auto_inject is the one that works without touching app templates.
     */
    public function addRow(Row $row, ?BaseItemDto $dto): void
    {
        $canonicalUrl = $this->urlGenerator->generate(
            'survos_folio_row_show',
            $row->getRp(),
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $collection = $this->addCollection($row);
        $webPage = $this->addWebPage($row, $dto, $canonicalUrl, $collection);

        $item = $this->addItem($row, $dto, $canonicalUrl);
        if (null === $item) {
            // A DTO type with no #[SchemaOrg] (postcard, correspondence, artifact, …). The
            // WebPage above is still true and still published; what is NOT done is inventing
            // a schema.org type for the object itself. Guessing "CreativeWork" for anything
            // unannotated would publish a claim nobody made, and would hide the gap — the
            // fix is an #[SchemaOrg] on the DTO, where the type decision belongs.
            return;
        }

        // mainEntityOfPage is a Thing property, so it is safe whatever the node turned out to
        // be. isPartOf is not — it is CreativeWork-only, and a PlaceDto row's node is a Place.
        $item->setProperty('mainEntityOfPage', $webPage->referenced());
        if ($item instanceof CreativeWorkContract) {
            $item->isPartOf($collection->referenced());
        }
        $webPage->mainEntity($item->referenced());
    }

    /**
     * The folio itself, as the collection this row belongs to.
     *
     * schema.org `Collection` is the archival sense of the word — a set of items held
     * together — which is what a folio is, and it is a CreativeWork, so both the row's
     * `isPartOf` and the page's `isPartOf` can point at this one node. (CollectionPage
     * would describe the *listing page*, a different thing, and could not carry the
     * item's membership.) Keyed on the folio's own show URL, so every row page in a
     * folio contributes the same single node.
     */
    private function addCollection(Row $row): Collection
    {
        $folio = $row->core->folio;
        $folioUrl = $this->urlGenerator->generate(
            'survos_folio_show',
            ['folioCode' => $folio->code],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $id = $folioUrl . '#collection';
        $collection = $this->schemaOrg->getOrCreate(Collection::class, $id);
        $collection
            ->identifier($id)
            ->url($folioUrl)
            ->name($folio->label ?: $folio->code);

        return $collection;
    }

    private function addWebPage(Row $row, ?BaseItemDto $dto, string $canonicalUrl, Collection $collection): WebPage
    {
        $id = $canonicalUrl . '#webpage';
        $webPage = $this->schemaOrg->getOrCreate(WebPage::class, $id);
        $webPage
            ->identifier($id)
            ->url($canonicalUrl)
            ->isPartOf($collection->referenced());

        // Row::displayTitle() is the same chain the detail template's <h1> uses, so the page and
        // its structured data can never disagree. Null (rather than the localId) when the data
        // has no real name — an absent name beats asserting the photograph is called "1".
        if (null !== $name = $row->displayTitle()) {
            $webPage->name($name);
        }

        return $webPage;
    }

    /** The row's object itself, or null when its DTO carries no #[SchemaOrg] type. */
    private function addItem(Row $row, ?BaseItemDto $dto, string $canonicalUrl): ?object
    {
        if (null === $dto || !$this->metadata->supports($dto::class)) {
            return null;
        }

        // Site root, taken off the canonical URL rather than generated from a route: it only
        // seeds the {base} of #[SchemaProperty] idPatterns and the synthetic /people/ and
        // /institutions/ @ids below, none of which are routes anything serves. Deriving it
        // from a real folio route would bake that route's locale/prefix into an identity.
        $siteUrl = $this->siteUrl($canonicalUrl);

        // Attribute-mapped scalars first (type, name, description, sameAs); the node comes
        // back as ordinary spatie fluent code for the rest.
        $node = $this->mapper->add($dto, $canonicalUrl . '#item', $siteUrl);
        $node->setProperty('url', $canonicalUrl);

        // $title maps to `name` via #[SchemaProperty]; displayTitle() supplies the fallbacks for
        // the many sources that ship no title at all, and yields null rather than inventing one.
        if (null === $dto->title || '' === trim($dto->title)) {
            if (null !== $name = $row->displayTitle()) {
                $node->setProperty('name', $name);
            }
        }

        $this->addImage($row, $dto, $node, $canonicalUrl);

        // Everything below is CreativeWork-only. PlaceDto, PoiDto and EventDto also extend
        // BaseItemDto, and a Place is not a CreativeWork — emitting `creator` or `license`
        // on one would be invalid, not merely unhelpful. Same reason the base DTO annotates
        // only Thing-level properties (see BaseItemDto's own note).
        if (!$node instanceof CreativeWorkContract) {
            return $node;
        }

        if (null !== $dto->year) {
            // dateCreated wants a date, and a bare year IS one in schema.org's ISO-8601 sense.
            // $date is free text from hundreds of sources ("ca. 1890", "1920s"), so it is only
            // used when there is no parsed year to prefer.
            $node->dateCreated((string) $dto->year);
        } elseif (\is_string($dto->date) && '' !== $dto->date) {
            $node->dateCreated($dto->date);
        }

        if (\is_string($dto->credit) && '' !== $dto->credit) {
            $node->creditText($dto->credit);
        }

        $this->addRights($dto, $node);

        if (\is_string($dto->language) && '' !== $dto->language) {
            $node->inLanguage($dto->language);
        }

        if (\is_string($dto->citation) && '' !== $dto->citation) {
            $node->citation($dto->citation);
        }

        $this->addCreators($dto, $node, $siteUrl);
        $this->addInstitution($dto, $node, $siteUrl);
        $this->addKeywords($dto, $node);
        $this->addContentLocation($dto, $node, $canonicalUrl);

        return $node;
    }

    /**
     * Rights, split by what schema.org's property types actually accept.
     *
     * `license` and `usageInfo` both take a URL or a CreativeWork — never bare text. But
     * $rightsUri as harvested is frequently not a URI at all: Fortepan's rows carry
     * "CC-BY-SA-3.0", a licence *identifier*. Emitting that as `license` publishes a
     * malformed URL, and mapping it to a canonical CC deed here would be this bundle
     * inventing a link the source never gave. So a real URL becomes `license`, and
     * anything else becomes `conditionsOfAccess`, which is Text-typed and therefore true.
     *
     * The upgrade is to normalise $rightsUri to a real URI during the harvest, where the
     * source's own licence vocabulary is known. Then this falls through to `license`.
     */
    private function addRights(BaseItemDto $dto, CreativeWorkContract $node): void
    {
        $uri = \is_string($dto->rightsUri) ? trim($dto->rightsUri) : '';
        $label = \is_string($dto->rights) ? trim($dto->rights) : '';

        if ('' !== $uri && false !== filter_var($uri, \FILTER_VALIDATE_URL)) {
            $node->license($uri);
            $uri = '';
        }

        $text = $label ?: $uri;
        if ('' !== $text) {
            $node->conditionsOfAccess($text);
        }
    }

    /** Scheme + host of an absolute URL, with no trailing slash. */
    private function siteUrl(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return \sprintf('%s://%s%s', $scheme, $host, $port);
    }

    /**
     * The row's image as an ImageObject.
     *
     * The RAW source URL, deliberately, not the imgproxy-signed one the page renders with:
     * a signed imgproxy URL is a rendering detail with its own expiry semantics, and
     * publishing it as the image's identity would hand consumers a link that outlives
     * nothing. `image` is a Thing property, so this is safe on Place/Event nodes too.
     */
    private function addImage(Row $row, BaseItemDto $dto, object $node, string $canonicalUrl): void
    {
        $contentUrl = $row->getThumbnailSource();
        if (null === $contentUrl) {
            return;
        }

        $id = $canonicalUrl . '#image';
        $image = $this->schemaOrg->getOrCreate(ImageObject::class, $id);
        // contentUrl only, no `url`: `url` is the image's own landing page, and pointing it
        // at the row page would claim this page IS the image rather than the object's record.
        $image
            ->identifier($id)
            ->contentUrl($contentUrl);

        if (\is_string($dto->thumbnailUrl) && '' !== $dto->thumbnailUrl) {
            $image->thumbnailUrl($dto->thumbnailUrl);
        }
        if (\is_string($dto->caption) && '' !== $dto->caption) {
            $image->caption($dto->caption);
        }

        $node->setProperty('image', $image->referenced());
    }

    /**
     * $creators holds names, not people — so each becomes a Person keyed on a URL derived
     * from the name. That is what keeps one photographer a single node across a page, and
     * across every page in the graph, instead of one node per mention.
     */
    private function addCreators(BaseItemDto $dto, CreativeWorkContract $node, string $siteUrl): void
    {
        $people = [];
        foreach ($dto->creators ?? [] as $name) {
            if (!\is_string($name) || '' === trim($name)) {
                continue;
            }
            $name = trim($name);

            $id = $siteUrl . '/people/' . rawurlencode(mb_strtolower($name));
            $person = $this->schemaOrg->getOrCreate(Person::class, $id);
            $person->identifier($id)->name($name);

            $people[] = $person->referenced();
        }

        if ([] !== $people) {
            $node->creator($people);
        }
    }

    /**
     * The holding institution as an Organization, on `sourceOrganization` rather than
     * `publisher`: these institutions hold and digitised the object, they did not publish
     * this page — the site did.
     */
    private function addInstitution(BaseItemDto $dto, CreativeWorkContract $node, string $siteUrl): void
    {
        if (!\is_string($dto->institution) || '' === trim($dto->institution)) {
            return;
        }
        $name = trim($dto->institution);

        $id = $siteUrl . '/institutions/' . rawurlencode(mb_strtolower($name));
        $organization = $this->schemaOrg->getOrCreate(Organization::class, $id);
        $organization->identifier($id)->name($name);

        $node->sourceOrganization($organization->referenced());
    }

    /** Subjects and tags as keywords — free-text terms, which is exactly what keywords is for. */
    private function addKeywords(BaseItemDto $dto, CreativeWorkContract $node): void
    {
        $keywords = [];
        foreach ([...($dto->subjects ?? []), ...($dto->tags ?? [])] as $term) {
            if (\is_string($term) && '' !== trim($term)) {
                $keywords[trim($term)] = true;
            }
        }

        if ([] !== $keywords) {
            $node->keywords(array_keys($keywords));
        }
    }

    /**
     * Where the object depicts/was made, as a Place with coordinates when they exist.
     *
     * contentLocation, not locationCreated: for a photograph the coordinates in this data
     * are the place shown, which is the one the sources actually record.
     */
    private function addContentLocation(BaseItemDto $dto, CreativeWorkContract $node, string $canonicalUrl): void
    {
        $nameParts = array_values(array_filter(
            [$dto->city, $dto->county, $dto->state, $dto->country],
            static fn (mixed $part): bool => \is_string($part) && '' !== trim($part),
        ));

        $hasGeo = null !== $dto->latitude && null !== $dto->longitude;
        if ([] === $nameParts && !$hasGeo) {
            return;
        }

        // Keyed on this row's own URL rather than on the place name: the parts are free text
        // from hundreds of sources, so two rows agreeing on "Springfield" is not evidence
        // they mean the same Springfield. A geonames-backed id would be, and is the upgrade
        // path here — $dto->geonamesId already carries it when the source provided one.
        $id = $canonicalUrl . '#place';
        $place = $this->schemaOrg->getOrCreate(Place::class, $id);
        $place->identifier($id);

        if ([] !== $nameParts) {
            $place->name(implode(', ', $nameParts));
        }
        if ($hasGeo) {
            $place->geo(Schema::geoCoordinates()->latitude($dto->latitude)->longitude($dto->longitude));
        }

        $node->contentLocation($place->referenced());
    }
}
