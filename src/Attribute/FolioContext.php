<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class FolioContext
{
    public function __construct(
        public readonly string $providerParam = 'provider',
        public readonly string $datasetParam  = 'dataset',
    ) {}
}
