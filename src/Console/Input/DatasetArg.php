<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Console\Input;

use Symfony\Component\Console\Attribute\Argument;

class DatasetArg
{
    #[Argument('Dataset key (e.g. mus/aust or mus_aust)')]
    public string $dataset {
        set(string $value) {
            $key = strtolower(trim($value));
            $this->dataset = str_contains($key, '/') ? $key : str_replace('_', '/', $key);
        }
    }

    public function folioCode(): string
    {
        return $this->dataset;
    }
}
