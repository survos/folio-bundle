<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\Folio\Enum\PageType;
use Survos\FolioBundle\Entity\Core;
use Survos\FolioBundle\Entity\Folio;
use Survos\FolioBundle\Entity\Page;
use Survos\FolioBundle\Entity\Row;

/** Shared detached Row/Page mapping for readers, without ORM writes or a shadow database. */
final class DuckDbFolioRowMapper
{
    /** Detached presentation entities: no EntityManager, schema change, or SQLite shadow copy. */
    public static function hydrate(string $key, string $core, array $record, array $pages): Row
    {
        $row = new Row(new Core(new Folio($key), $core), $record['local_id']);
        if ($row->id !== $record['id']) {
            throw new \UnexpectedValueException('Folio row identity mismatch.');
        }
        $row->label = $record['label'];
        $row->dtoType = $record['dto_type'];
        $row->dtoData = self::json($record['dto_data']);
        $row->extras = self::json($record['extras']);
        $row->raw = self::json($record['raw']);
        foreach ($pages as $saved) {
            $page = new Page($row, (int) $saved['seq'], $saved['url']);
            if ($saved['row_id'] !== $row->id || $saved['id'] !== $page->id) {
                throw new \UnexpectedValueException('Folio page identity mismatch.');
            }
            $page->pageIndex = (int) $saved['page_index'];
            $page->type = null === $saved['type'] ? null : PageType::from($saved['type']);
            foreach (['source_url' => 'sourceUrl', 'media_id' => 'mediaId', 'text' => 'text', 'htr' => 'htr',
                'dense_summary' => 'denseSummary', 'thumb_hash' => 'thumbHash', 'color' => 'color', 'source_checksum' => 'sourceChecksum'] as $column => $property) {
                $page->$property = $saved[$column];
            }
            foreach (['width', 'height'] as $dimension) {
                $page->$dimension = null === $saved[$dimension] ? null : (int) $saved[$dimension];
            }
            foreach (['layout', 'ledger', 'dialogue'] as $property) {
                $page->$property = self::json($saved[$property]);
            }
            $row->pages->add($page);
        }

        return $row;
    }

    private static function json(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        }
        if (null !== $value && !is_array($value)) {
            throw new \UnexpectedValueException('Expected normalized JSON data.');
        }

        return $value;
    }
}
