<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/** .dfolio is canonical; existing .duckdb artifacts remain readable until rebuilt. */
final class DuckDbFolioFile
{
    public static function accepts(string $path): bool
    {
        return str_ends_with($path, '.dfolio') || str_ends_with($path, '.duckdb');
    }

    public static function existingPath(string $path): string
    {
        if (!is_file($path) && str_ends_with($path, '.dfolio')) {
            $legacy = substr($path, 0, -strlen('.dfolio')).'.duckdb';
            if (is_file($legacy)) {
                return $legacy;
            }
        }

        return $path;
    }
}
