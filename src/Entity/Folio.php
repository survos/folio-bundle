<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Survos\DataContracts\Metadata\PropertyKey;
use Survos\DataContracts\Metadata\PropertyValue;
use Survos\FieldBundle\Attribute\RouteIdentity;
use Survos\FieldBundle\Entity\RouteIdentityTrait;
use Survos\FieldBundle\Entity\RouteParametersInterface;
use Survos\FolioBundle\Repository\FolioRepository;

/**
 * Every folio route takes ONE path param, {folioCode} ("mus/fpus"), not the old
 * {provider}/{dataset} pair -- `provider` was pure noise: always re-derivable from the same
 * single `code` PK, but threaded as a separate variable through every route, controller
 * signature, and link-building call site anyway. #[RouteIdentity(field: 'code', key:
 * 'folioCode')] + RouteIdentityTrait give getRp()/getUniqueIdentifiers()/getClassnamePrefix()
 * for free -- no hand-written methods needed now that this is a single-field-to-single-param
 * mapping (RouteIdentityTrait can't handle the OLD two-param shape, which is why it wasn't used
 * before this route change).
 */
#[ORM\Entity(repositoryClass: FolioRepository::class)]
#[ORM\Table(name: 'folio')]
#[RouteIdentity(field: 'code', key: 'folioCode')]
class Folio implements RouteParametersInterface
{
    use RouteIdentityTrait;

    #[ORM\Id]
    #[ORM\Column(length: 120, options: ['comment' => 'Unique folio identifier, matches dataset key'])]
    public string $code;

    public ?string $label { get => $this->get(PropertyKey::LABEL); set { $this->set(PropertyKey::LABEL, $value); } }
    public ?string $description { get => $this->get(PropertyKey::DESCRIPTION); set { $this->set(PropertyKey::DESCRIPTION, $value); } }
    public array $tags { get => $this->get(PropertyKey::TAGS) ?? []; set { $this->set(PropertyKey::TAGS, $value); } }

    #[ORM\Column(length: 180, nullable: true, options: ['comment' => 'Dataset key from data-bundle (e.g. mus/cleveland)'])]
    public ?string $datasetKey = null;

    public int $rowCount { get => $this->get(PropertyKey::ROW_COUNT) ?? 0; set { $this->set(PropertyKey::ROW_COUNT, $value, 'build', 'folio.build'); } }

    /** Search text is stored in the FTS table (snippet() works) — every folio unless it opts out. */
    public const string FTS_CONTENT_STORED = 'stored';
    /**
     * Index only: a contentless FTS5 table. For text-heavy folios (newspapers) whose rows already
     * hold every word, the stored copy was the largest thing in the file. snippet() returns nothing;
     * readers build snippets from the row text (FolioSnippet).
     */
    public const string FTS_CONTENT_NONE = 'none';
    /**
     * No FTS table at all: this folio's text search lives in Elasticsearch. Carried here from the
     * dataset's declared search policy (extras.search — backend: elasticsearch, allowFtsSkip: true;
     * see FolioSearchConfiguration and docs/search-policy.md) so a reader can say "search is not in
     * this file" rather than inferring it from a missing table, which is also what a half-finished
     * build looks like. The build applies it past survos_folio.fts_max_rows: on news/rappnews4909 —
     * 966,590 page rows, 1.3 GB of OCR — the index is the largest table in a 6 GB folio and the
     * longest phase of every rebuild.
     */
    public const string FTS_CONTENT_OFF = 'off';

    /**
     * What the folio is, from the dataset's meta (extras.contentType): newspaper, periodical, ...
     * Published so readers such as Ink can tell a newspaper folio from a museum collection.
     */
    public ?string $contentType { get => $this->get(PropertyKey::CONTENT_TYPE); set { $this->set(PropertyKey::CONTENT_TYPE, $value); } }

    #[ORM\Column(length: 12, options: ['default' => self::FTS_CONTENT_STORED, 'comment' => 'stored | none (contentless FTS) | off (no FTS at all); opt-in via dataset meta extras.ftsContent'])]
    public string $ftsContent = self::FTS_CONTENT_STORED;

    /** @var Collection<int, LinkType> */
    #[ORM\OneToMany(targetEntity: LinkType::class, mappedBy: 'folio', fetch: 'EXTRA_LAZY')]
    public Collection $linkTypes;

    /** @var array<string, PropertyValue> */
    private array $properties = [];
    private array $pending = [];

    public function get(string $key): mixed { return isset($this->properties[$key]) ? $this->properties[$key]->value : ($key === PropertyKey::SCHEMA_VERSION ? 1 : null); }
    public function has(string $key): bool { return array_key_exists($key, $this->properties); }
    public function properties(): array { return $this->properties; }
    public function pendingProperties(): array { return $this->pending; }
    public function markPropertiesSaved(): void { $this->pending = []; }
    public function loadProperties(array $properties): void { $this->properties = $properties; $this->pending = []; }

    public function set(string $key, mixed $value, string $source = 'meta', string $owner = 'folio.meta', array $provenance = []): void
    {
        $this->put($key, PropertyValue::create($value, $source, $owner, $provenance));
    }

    public function put(string $key, PropertyValue $property): void
    {
        PropertyKey::validate($key, $property->value);
        if ($property->source === 'human' && in_array($key, [PropertyKey::ROW_COUNT, PropertyKey::SCHEMA_VERSION], true)) {
            throw new \InvalidArgumentException('Cannot override a system property: '.$key);
        }
        $old = $this->properties[$key] ?? null;
        if ($old !== null && $property->source !== 'human' && $old->owner !== $property->owner
            && !($old->owner === 'legacy.folio' && in_array($key, [PropertyKey::LABEL, PropertyKey::CONTENT_TYPE, PropertyKey::ROW_COUNT], true))) {
            return;
        }
        if ($old !== null && $old->value === $property->value && $old->source === $property->source && $old->owner === $property->owner && $old->provenance === $property->provenance) { return; }
        $this->properties[$key] = $this->pending[$key] = $property;
    }

    /** Replace only this writer's snapshot; another writer's properties are preserved. */
    public function replaceProperties(string $owner, array $properties): void
    {
        foreach ($properties as $key => $property) {
            if ($property->owner !== $owner) { throw new \InvalidArgumentException('Property owner mismatch'); }
            PropertyKey::validate($key, $property->value);
        }
        foreach ($this->properties as $key => $property) {
            if ($property->owner === $owner && !array_key_exists($key, $properties)) {
                unset($this->properties[$key]);
                $this->pending[$key] = null;
            }
        }
        foreach ($properties as $key => $property) { $this->put($key, $property); }
    }

    public function __construct(string $code)
    {
        $this->code = $code;
        $this->set(PropertyKey::SCHEMA_VERSION, 2, 'build', 'folio.format');
        $this->linkTypes = new ArrayCollection();
    }
}
