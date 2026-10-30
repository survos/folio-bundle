<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\SearchBundle\Adapter\DuckDb\DuckDbFtsIndexer;

/** The folio search contract, projected from declared DTO fields. */
final readonly class DuckDbFolioSearchProjection
{
    public const int VERSION = 1;

    public function __construct(private DuckDbFtsIndexer $fts = new DuckDbFtsIndexer())
    {
    }

    /** Caller owns the staging file, transaction and atomic publication. */
    public function build(\PDO $db, bool $fts = true): int
    {
        if ($fts) {
            $db->exec('LOAD fts');
        }
        $db->exec('DROP SCHEMA IF EXISTS fts_main_search_records CASCADE');
        $db->exec('DROP TABLE IF EXISTS search_records');
        $tables = [];
        foreach ($db->query('SELECT dto_type, structure::VARCHAR AS structure FROM _dto_schema ORDER BY dto_type')->fetchAll(\PDO::FETCH_ASSOC) as $contract) {
            $shape = json_decode($contract['structure'], true, flags: JSON_THROW_ON_ERROR);
            $columns = ['"_itemId" AS id', '"_coreId" AS coreId', 'split_part("_coreId", \':\', -1) AS core'];
            foreach (['id' => ['localId', 'VARCHAR'], 'title' => ['title', 'VARCHAR'], 'description' => ['description', 'VARCHAR'], 'year' => ['year', 'BIGINT'], 'thumbnailUrl' => ['thumbnailUrl', 'VARCHAR'], 'sourceUrl' => ['sourceUrl', 'VARCHAR'], 'cul' => ['culture', 'VARCHAR[]']] as $property => [$alias, $type]) {
                $columns[] = (isset($shape[$property]) ? 'CAST("'.$property.'" AS '.$type.')' : 'NULL::'.$type).' AS "'.$alias.'"';
            }
            $columns[] = $db->quote($contract['dto_type']).' AS dtoType';
            $tables[] = 'SELECT '.implode(', ', $columns).' FROM dto."'.str_replace('"', '""', $contract['dto_type']).'"';
        }
        if (!$tables) {
            throw new \RuntimeException('No DTO schema contracts in this companion.');
        }
        $db->exec('CREATE TABLE search_records AS '.implode(' UNION ALL ', $tables));
        if ($fts) {
            $this->fts->build($db, 'search_records', 'id', ['title', 'description']);
        }

        return (int) $db->query('SELECT count(*) FROM search_records')->fetchColumn();
    }
}
