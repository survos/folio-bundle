<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Model;

final readonly class FolioInput
{
    public string $provider;
    public string $dataset;
    public string $folioCode;

    public function __construct(string $dataset)
    {
        $key = strtolower(trim($dataset));
        // normalise underscore refs: mus_aust → mus/aust
        if (!str_contains($key, '/') && str_contains($key, '_')) {
            $key = str_replace('_', '/', $key);
        }
        $parts = explode('/', $key, 2);
        $this->provider  = $parts[0];
        $this->dataset   = $parts[1] ?? $parts[0];
        $this->folioCode = $key;
    }
}
