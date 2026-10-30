<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\DataContracts\Path\DataPaths;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class LocalDuckDbFolioLocator implements DuckDbFolioLocatorInterface
{
    /** @param array<string,string> $sources Trusted overrides, including https:// and s3:// URIs. */
    public function __construct(private DataPaths $paths, private array $sources = [])
    {
    }

    public function locate(string $folioCode): string
    {
        DuckDbFolioConnection::validate($folioCode);
        if (isset($this->sources[$folioCode])) {
            return $this->sources[$folioCode];
        }
        $path = $this->paths->folioFile($folioCode, 'duckdb');
        if (!is_file($path)) {
            throw new NotFoundHttpException('No DuckDB working copy for '.$folioCode);
        }

        return $path;
    }
}
