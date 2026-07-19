<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Model;

use Survos\IiifBundle\Service\IiifUrl;

final readonly class FolioChatHit
{
    /**
     * @param array<string, mixed> $dtoData
     * @param array<string, mixed> $extras
     */
    public function __construct(
        public string $provider,
        public string $dataset,
        public string $coreCode,
        public string $localId,
        public ?string $label,
        public ?string $dtoType,
        public float $score,
        public string $snippet,
        public array $dtoData,
        public array $extras,
        public ?string $pageUrl = null,
        public ?string $pageMediaId = null,
    ) {}

    /**
     * @return array{folioCode: string, coreCode: string, dtoType: string, localId: string}
     */
    public function routeParams(): array
    {
        return [
            'folioCode' => "$this->provider/$this->dataset",
            'coreCode' => $this->coreCode,
            'dtoType' => $this->dtoType ?: 'row',
            'localId' => $this->localId,
        ];
    }

    public function citationUrl(): ?string
    {
        return $this->stringValue('citation_url');
    }

    /**
     * Source image for the row's pages[0]. Returned raw (the stored page url) so the template can
     * pass it through imgproxy exactly as the row detail view does ({@see detail.html.twig}:
     * `page.url|imgproxy('thumb')`) — imgproxy fetches and resizes, including IIIF urls. Folios
     * whose dtoData carries an image field (iiif_base/large/thumbnail) fall back to that.
     */
    public function thumbnailSource(): ?string
    {
        if ($this->pageUrl !== null && trim($this->pageUrl) !== '') {
            return $this->pageUrl;
        }

        return $this->stringValue('iiif_base')
            ?? $this->stringValue('large_image_url')
            ?? $this->stringValue('thumbnail_url');
    }

    public function thumbnailUrl(): ?string
    {
        if ($this->pageUrl !== null && trim($this->pageUrl) !== '') {
            return $this->pageUrl;
        }

        $iiifBase = $this->stringValue('iiif_base');
        if ($iiifBase) {
            return IiifUrl::imageUrl($iiifBase, '!300,300');
        }

        return $this->stringValue('thumbnail_url')
            ?? $this->stringValue('large_image_url');
    }

    public function denseSummary(): ?string
    {
        return $this->stringValue('ai:denseSummary')
            ?? $this->stringValue('search_summary');
    }

    /**
     * The row's own descriptive text — the richest grounding the chat can give the model when
     * no AI denseSummary exists (e.g. dc/nara, which carry a real `description`). Truncated so a
     * batch of hits stays within a sane prompt budget.
     */
    public function description(int $maxLen = 600): ?string
    {
        $value = $this->stringValue('description');
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $maxLen ? rtrim(mb_substr($value, 0, $maxLen)) . '…' : $value;
    }

    private function stringValue(string $key): ?string
    {
        $value = $this->dtoData[$key] ?? $this->extras[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
