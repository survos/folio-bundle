<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
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

    #[ORM\Column(length: 255, nullable: true, options: ['comment' => 'Human-readable display name'])]
    public ?string $label = null;

    #[ORM\Column(length: 180, nullable: true, options: ['comment' => 'Dataset key from data-bundle (e.g. mus/cleveland)'])]
    public ?string $datasetKey = null;

    #[ORM\Column(options: ['default' => 0, 'comment' => 'Total rows across all cores'])]
    public int $rowCount = 0;

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
    #[ORM\Column(length: 40, nullable: true, options: ['comment' => 'Dataset content type (newspaper, periodical, ...)'])]
    public ?string $contentType = null;

    #[ORM\Column(length: 12, options: ['default' => self::FTS_CONTENT_STORED, 'comment' => 'stored | none (contentless FTS) | off (no FTS at all); opt-in via dataset meta extras.ftsContent'])]
    public string $ftsContent = self::FTS_CONTENT_STORED;

    /** @var Collection<int, LinkType> */
    #[ORM\OneToMany(targetEntity: LinkType::class, mappedBy: 'folio', fetch: 'EXTRA_LAZY')]
    public Collection $linkTypes;

    public function __construct(string $code)
    {
        $this->code = $code;
        $this->linkTypes = new ArrayCollection();
    }
}
