<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

final class FolioMeiliDocumentBuilder
{
    /**
     * @param array<string,mixed>|null $dtoData
     * @param array<string,mixed>|null $extras
     * @return array<string,mixed>
     */
    public function build(
        string $folioCode,
        string $coreCode,
        string $rowId,
        string $localId,
        ?string $label,
        ?string $dtoType,
        ?array $dtoData,
        ?array $extras,
    ): array {
        [$provider, $dataset] = array_pad(explode('/', $folioCode, 2), 2, '');

        $doc = is_array($dtoData) ? $dtoData : [];
        $doc['id'] = $rowId;
        $doc['folioCode'] = $folioCode;
        $doc['provider'] = $provider;
        $doc['dataset'] = $dataset;
        $doc['coreCode'] = $coreCode;
        $doc['localId'] = $localId;
        $doc['dtoType'] = $dtoType;
        $doc['label'] = $label ?? ($doc['title'] ?? $localId);
        $doc['rp'] = [
            'provider' => $provider,
            'dataset' => $dataset,
            'coreCode' => $coreCode,
            'dtoType' => $dtoType ?? '',
            'localId' => $localId,
        ];

        if (is_array($extras) && $extras !== []) {
            $doc['extras'] = $extras;
        }

        return $this->sparse($doc);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function sparse(array $data): array
    {
        return array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }
}
