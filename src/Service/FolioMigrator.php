<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\Folio\PropertyStore;

/**
 * Brings one existing folio file up to the deployed contracts without rewriting its rows: converts the
 * metadata, re-derives the schema (facet/filter flags come from the DTO attributes), views and docs, then
 * rebuilds the browse indexes and facet counts, as a build ends. One folio only — which folios to migrate,
 * and in what order, is the owning application's business.
 */
final readonly class FolioMigrator
{
    public function __construct(
        private FolioArchivePreparer $preparer,
        private FolioFtsIndexer $indexer,
    ) {
    }

    /**
     * Facets are rebuilt; the full-text index is not touched (folios rely on Elasticsearch for text search, and
     * migrating never changes a row, so an index a folio still has stays valid).
     *
     * @return array{items: int}
     */
    public function migrate(string $file): array
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException('Folio file not found: '.$file);
        }
        $pdo = new \PDO('sqlite:'.$file, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='folio'")->fetchColumn()) {
            throw new \InvalidArgumentException('Not a folio: '.$file);
        }
        (new PropertyStore($pdo))->migrate();
        unset($pdo);

        $this->preparer->prepare($file, ['migrated' => true]);

        return ['items' => $this->indexer->rebuildFacets($file)];
    }
}
