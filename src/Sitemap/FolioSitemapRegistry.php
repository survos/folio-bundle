<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Sitemap;

use Survos\DatasetBundle\Entity\Artifact;
use Survos\DatasetBundle\Repository\ArtifactRepository;

/**
 * Which folios belong in the sitemap, and when each was last built.
 *
 * Kept separate from FolioSitemapPopulator so "what is publishable" is one decision in one place:
 * a folio with no artifact row, or no file on disk, must not appear in a sitemap -- submitting
 * URLs that 404 is worse than omitting them, and /en/f/mus/larco is a live example of a folio
 * code that exists in code and docs but has no build on the volume.
 */
final class FolioSitemapRegistry
{
    public function __construct(
        private readonly ArtifactRepository $artifacts,
    ) {
    }

    /** @return list<string> */
    public function publishedFolioCodes(): array
    {
        $rows = $this->artifacts->createQueryBuilder('artifact')
            ->select('dataset.datasetKey AS code', 'artifact.uri AS uri')
            ->join('artifact.dataset', 'dataset')
            ->where('artifact.type = :type')
            ->setParameter('type', Artifact::TYPE_FOLIO)
            ->orderBy('dataset.datasetKey', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $codes = [];
        foreach ($rows as $row) {
            $uri = $row['uri'] ?? null;
            if (!\is_string($uri) || !is_file($uri)) {
                continue;
            }
            $codes[] = (string) $row['code'];
        }

        return $codes;
    }

    public function lastModified(string $folioCode): ?\DateTimeInterface
    {
        $row = $this->artifacts->createQueryBuilder('artifact')
            ->select('artifact.updatedAt AS updatedAt')
            ->join('artifact.dataset', 'dataset')
            ->where('artifact.type = :type')
            ->andWhere('dataset.datasetKey = :code')
            ->setParameter('type', Artifact::TYPE_FOLIO)
            ->setParameter('code', $folioCode)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row['updatedAt'] ?? null;
    }
}
