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
    ) {}

    /**
     * @return array{provider: string, dataset: string, coreCode: string, dtoType: string, localId: string}
     */
    public function routeParams(): array
    {
        return [
            'provider' => $this->provider,
            'dataset' => $this->dataset,
            'coreCode' => $this->coreCode,
            'dtoType' => $this->dtoType ?: 'row',
            'localId' => $this->localId,
        ];
    }

    public function citationUrl(): ?string
    {
        return $this->stringValue('citation_url');
    }

    public function thumbnailSource(): ?string
    {
        return $this->stringValue('iiif_base')
            ?? $this->stringValue('large_image_url')
            ?? $this->stringValue('thumbnail_url');
    }

    public function thumbnailUrl(): ?string
    {
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

    private function stringValue(string $key): ?string
    {
        $value = $this->dtoData[$key] ?? $this->extras[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
