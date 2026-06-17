<?php
declare(strict_types=1);

namespace Survos\FolioBundle\Enum;

/**
 * The imagery-role / medium of a Page — what the viewer renders and which AI path applies.
 * Authored by the normalizer (best guess), mutable later by AI or a human.
 *
 * This is NOT the content genre (that's ContentType on the row) and NOT the media STI medium
 * (that's BaseMedia.type). It's a superset of the media medium: Photo/Scan/Document all map to
 * medium `image`, Audio→audio, Video→video — while carrying the finer OCR-vs-visual signal folio
 * needs. See folio-bundle/docs/pages-and-media.md.
 */
enum PageType: string
{
    case Photo    = 'photo';     // a photograph OF a physical object/scene → visual AI
    case Scan     = 'scan';      // a flat digitisation of a page/document → OCR
    case Document = 'document';  // a born-digital page (PDF render) → OCR
    case Audio    = 'audio';     // future
    case Video    = 'video';     // future
    case Other    = 'other';
}
