<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Sitemap;

use Survos\FolioBundle\Catalog\FolioCatalogClient;
use Survos\FolioBundle\Service\FolioService;

/** Published catalog entries that have a local folio available for item enumeration. */
final readonly class FolioSitemapRegistry
{
    public function __construct(private FolioCatalogClient $catalog, private FolioService $folios)
    {
    }

    /** @return list<string> */
    public function publishedFolioCodes(): array
    {
        $entries = $this->catalog->all();
        if ([] === $entries && $this->catalog->isStale()) {
            throw new \RuntimeException('Cannot build a sitemap without a published folio catalog.');
        }
        $codes = [];
        foreach ($entries as $entry) {
            if (null === $entry->locale && is_file($this->folios->path($entry->datasetKey))) {
                $codes[] = $entry->datasetKey;
            }
        }

        return array_values(array_unique($codes));
    }

    public function lastModified(string $folioCode): ?\DateTimeInterface
    {
        $file = $this->folios->path($folioCode);

        return is_file($file) ? (new \DateTimeImmutable())->setTimestamp(filemtime($file)) : null;
    }
}
