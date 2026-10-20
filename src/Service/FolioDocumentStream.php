<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Survos\Folio\Enum\PageType;
use Survos\FolioBundle\Set\ItemCriteria;

/**
 * Streams folio rows as flat search documents, for any engine.
 *
 * Extracted from FolioMeiliBuildSetCommand so a pooled Elasticsearch index reads exactly the
 * same documents the combined Meili indexes did. Rows are read straight from each folio's SQLite
 * file; nothing is buffered, so the caller decides how to batch the upload.
 */
final class FolioDocumentStream
{
    /** Identity/routing keys always carried over from the built document. */
    public const IDENTITY = ['id', 'folioCode', 'provider', 'dataset', 'coreCode', 'localId', 'dtoType', 'label', 'rp'];

    /** Media/thumbnail + outbound-link keys carried over verbatim when present (used by hit templates). */
    public const MEDIA = ['pageUrl', 'thumbnailUrl', 'largeImageUrl', 'displayImageUrl', 'iiifBase', 'sourceUrl', 'citationUrl'];

    /** Whitelisted field -> candidate source keys (first non-empty wins). Normalises ai:-prefixed keys. */
    public const SOURCES = [
        'caption' => ['ai:caption', 'caption'],
        'denseSummary' => ['ai:denseSummary', 'denseSummary', 'searchSummary'],
    ];

    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioMeiliDocumentBuilder $documentBuilder,
    ) {
    }

    /**
     * @param list<string>      $folioCodes
     * @param list<string>|null $keep         content fields to keep; null keeps every dtoData key
     * @param list<string>|null $contentTypes when set, scans every core and filters by dtoData.contentType instead of $core
     * @param list<string>      $extraKeys    extras keys lifted onto the document
     *
     * @return \Generator<array<string,mixed>>
     */
    public function documents(
        array $folioCodes,
        string $core = 'obj',
        ?array $keep = null,
        ?string $openLocale = null,
        ?array $contentTypes = null,
        array $extraKeys = [],
        ?FolioDocumentStreamReport $report = null,
        ?ItemCriteria $itemCriteria = null,
    ): \Generator {
        $report ??= new FolioDocumentStreamReport();
        $itemCriteria ??= new ItemCriteria();

        // Core code is schema/table naming, not a content signal — the same folio can file real
        // objects and scanned text-document pages under the same core (see NARA). $contentTypes
        // filters on the item's actual dtoData.contentType across every core in the folio instead.
        $select = 'SELECT i.id, i.local_id, i.label, i.dto_type, i.dto_data, i.extras, c.code AS core_code';
        $from = "\nFROM item i\nJOIN core c ON c.id = i.core_id\n";
        $where = null !== $contentTypes
            ? "WHERE json_extract(i.dto_data, '$.contentType') IN (:contentTypes)\n"
            : "WHERE c.code = :core\n";

        // At the scale a pooled build runs at (thousands of independently-built folios), a single
        // stale/moved/schema-drifted file is expected, not exceptional — one bad folio must not
        // abort the whole streamed upload. Skip and keep going.
        foreach ($folioCodes as $folioCode) {
            $report->perFolio[$folioCode] = 0;

            try {
                $connection = $this->folios->context($folioCode, locale: $openLocale)->em->getConnection();
                // The first page is a row's canonical image (Row::getRawThumbnailSource()). Folios
                // built before pages existed have no page table, and those rows get no pageUrl.
                $hasPages = (bool) $connection->fetchOne("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'page'");
                if ($itemCriteria->minImages > 0 && !$hasPages) {
                    continue; // Legacy folios without attached pages have zero image pages.
                }
                $params = null !== $contentTypes ? ['contentTypes' => $contentTypes] : ['core' => $core];
                $types = null !== $contentTypes ? ['contentTypes' => ArrayParameterType::STRING] : [];
                $imageWhere = '';
                if ($itemCriteria->minImages > 0) {
                    $imageWhere = 'AND (SELECT COUNT(*) FROM page images WHERE images.row_id = i.id'
                        .' AND images.type IN (:imageTypes) AND length(trim(images.url)) > 0) >= :minImages ';
                    $params['imageTypes'] = [PageType::Photo->value, PageType::Scan->value, PageType::Document->value];
                    $params['minImages'] = $itemCriteria->minImages;
                    $types['imageTypes'] = ArrayParameterType::STRING;
                    $types['minImages'] = ParameterType::INTEGER;
                }
                $sql = $select
                    .($hasPages ? ', (SELECT p.url FROM page p WHERE p.row_id = i.id ORDER BY p.seq LIMIT 1) AS page_url' : ', NULL AS page_url')
                    .$from.$where.$imageWhere.'ORDER BY i.id';
                $result = $connection->executeQuery($sql, $params, $types);

                while (($row = $result->fetchAssociative()) !== false) {
                    $doc = $this->documentBuilder->build(
                        $folioCode,
                        (string) $row['core_code'],
                        (string) $row['id'],
                        (string) $row['local_id'],
                        null !== $row['label'] ? (string) $row['label'] : null,
                        null !== $row['dto_type'] ? (string) $row['dto_type'] : null,
                        $this->decodeJson($row['dto_data'] ?? null),
                        null, // common-field index: drop source-specific extras (we lift only source_tags below)
                    );

                    if (is_string($row['page_url']) && '' !== $row['page_url']) {
                        $doc['pageUrl'] = $row['page_url'];
                    }

                    $extras = $this->decodeJson($row['extras'] ?? null);
                    // Legacy fallback only: canonical tags also carry AI provenance and must win.
                    if (empty($doc['tags']) && is_array($extras) && ($extras['source_tags'] ?? null)) {
                        $doc['tags'] = $extras['source_tags'];
                    }
                    // Anything else the caller named. Scalars and lists only: extras also holds
                    // whole sub-documents (a newspaper article's `segments` carries every word of
                    // it, with boxes) and pooling those would multiply the index by the thing it
                    // is meant to be an index of.
                    foreach ($extraKeys as $key) {
                        $value = is_array($extras) ? ($extras[$key] ?? null) : null;
                        if (null === $value || '' === $value || [] === $value) {
                            continue;
                        }
                        if (is_scalar($value) || (is_array($value) && $value === array_filter($value, 'is_scalar'))) {
                            $doc[$key] = $value;
                        }
                    }

                    // Match before field projection, so keep: ['title'] cannot disable a tag filter.
                    if (!$itemCriteria->matchesTags(is_array($doc['tags'] ?? null) ? $doc['tags'] : [])) {
                        continue;
                    }

                    ++$report->count;
                    ++$report->perFolio[$folioCode];
                    yield $this->project($doc, $keep);
                }
            } catch (\Throwable $e) {
                $report->failed[$folioCode] = $e->getMessage();
            }
        }
    }

    /**
     * @param array<string,mixed> $doc
     * @param list<string>|null   $keep
     *
     * @return array<string,mixed>
     */
    private function project(array $doc, ?array $keep): array
    {
        $doc = $this->normalizeAliases($doc);
        $out = null === $keep ? $doc : $this->projectWhitelist($doc, $keep);

        // The row id is a composite "folioCode:coreCode:localId" (e.g. "mus/fortepan:obj:1"),
        // whose "/" and ":" are illegal in a Meili document id. Keep the original as rowId and
        // use a sanitised, still-unique id as the primary key.
        if (isset($out['id'])) {
            $out['rowId'] = $out['id'];
            $out['id'] = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $out['id']);
        }

        return $out;
    }

    /**
     * Lift ai:-prefixed (and other aliased) keys onto their canonical field name in place, so
     * both the whitelist and all-fields paths see e.g. `caption` regardless of which source key
     * the folio actually populated.
     *
     * @param array<string,mixed> $doc
     *
     * @return array<string,mixed>
     */
    private function normalizeAliases(array $doc): array
    {
        foreach (self::SOURCES as $field => $candidates) {
            if (($doc[$field] ?? null) !== null && '' !== $doc[$field] && [] !== $doc[$field]) {
                continue;
            }
            foreach ($candidates as $src) {
                $value = $doc[$src] ?? null;
                if (null !== $value && '' !== $value && [] !== $value) {
                    $doc[$field] = $value;
                    break;
                }
            }
        }

        return $doc;
    }

    /**
     * @param array<string,mixed> $doc
     * @param list<string>        $keep
     *
     * @return array<string,mixed>
     */
    private function projectWhitelist(array $doc, array $keep): array
    {
        $out = [];
        foreach (self::IDENTITY as $k) {
            if (array_key_exists($k, $doc)) {
                $out[$k] = $doc[$k];
            }
        }

        // Thumbnail/image URLs for the hit template — kept verbatim when present.
        foreach (self::MEDIA as $k) {
            if (($doc[$k] ?? null) !== null && '' !== $doc[$k]) {
                $out[$k] = $doc[$k];
            }
        }

        foreach ($keep as $field) {
            if (($doc[$field] ?? null) !== null && '' !== $doc[$field] && [] !== $doc[$field]) {
                $out[$field] = $doc[$field];
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
