<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Entity;

use ApiPlatform\Metadata\ApiProperty;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * One viewable page of a {@see Row} — the canonical, relational form of a row's
 * imagery. Any row (a NARA document, an object, …) can have pages.
 *
 *   - a multi-jpg document → one Page per jpg (each pageIndex 0)
 *   - a single-PDF document → one Page per page (same url, pageIndex 0..N-1)
 *
 * The row keeps its source link (e.g. the PDF) only for reference; pages are the
 * canonical thing we display and the relation we generate IIIF from. Per-page
 * artifacts (OCR text, summary, layout) live here — folded in from the central
 * media table's AI output, joined by {@see $mediaId} = xxh3(url).
 *
 * Media will later generalise to audio/video; for now a Page is an image page.
 */
#[EntityMeta(icon: 'mdi:file-image', group: 'Folio', label: 'Folio Pages', adminBrowsable: false)]
#[ORM\Entity]
#[ORM\Table(name: 'page')]
#[ORM\Index(name: 'idx_page_row', columns: ['row_id'])]
#[ORM\Index(name: 'idx_page_media', columns: ['media_id'])]
class Page
{
    #[ORM\Id]
    #[ORM\Column(length: 320, options: ['comment' => 'Composite: {rowId}#{seq}'])]
    #[Groups(['page:read'])]
    public string $id;

    #[ORM\ManyToOne(targetEntity: Row::class, inversedBy: 'pages')]
    #[ORM\JoinColumn(name: 'row_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public Row $row;

    #[ORM\Column(options: ['default' => 1])]
    #[ApiProperty('1-based order of the page within its row')]
    #[Groups(['page:read'])]
    #[Field(sortable: true)]
    public int $seq = 1;

    #[ORM\Column(name: 'page_index', options: ['default' => 0])]
    #[ApiProperty('0-based index within the source binary: 0 for a standalone jpg, the page number for a PDF')]
    #[Groups(['page:read'])]
    #[Field(sortable: true)]
    public int $pageIndex = 0;

    #[ORM\Column(type: Types::TEXT)]
    #[ApiProperty('Page image / source URL — the viewer uses url + pageIndex / IIIF')]
    #[Groups(['page:read'])]
    public string $url;

    #[ORM\Column(name: 'media_id', length: 32, nullable: true)]
    #[ApiProperty('xxh3(url): central media id — joins to media-bundle + its S3 AI sidecars')]
    #[Groups(['page:read'])]
    #[Field(filterable: true)]
    public ?string $mediaId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[ApiProperty('Plain OCR text for this page (folded from the media sidecar)')]
    #[Groups(['page:read'])]
    #[Field(searchable: true)]
    public ?string $text = null;

    #[ORM\Column(name: 'dense_summary', type: Types::TEXT, nullable: true)]
    #[ApiProperty('Information-dense ≤350-char summary the chatbot/search reads (folded from AI)')]
    #[Groups(['page:read'])]
    #[Field(searchable: true)]
    public ?string $denseSummary = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[ApiProperty('Structured ledger extraction (ledger-bundle): records mined from this page')]
    #[Groups(['page:read'])]
    public ?array $ledger = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[ApiProperty('Per-page layout blocks (type + bbox) for region highlighting in the viewer')]
    public ?array $layout = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty('Pixel width')]
    #[Groups(['page:read'])]
    #[Field(sortable: true)]
    public ?int $width = null;

    #[ORM\Column(nullable: true)]
    #[ApiProperty('Pixel height')]
    #[Groups(['page:read'])]
    #[Field(sortable: true)]
    public ?int $height = null;

    public function __construct(Row $row, int $seq, string $url)
    {
        $this->row = $row;
        $this->seq = $seq;
        $this->url = $url;
        $this->id = self::id($row->id, $seq);
    }

    public static function id(string $rowId, int $seq): string
    {
        return $rowId . '#' . $seq;
    }
}
