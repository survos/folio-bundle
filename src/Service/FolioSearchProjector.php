<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

/**
 * Turns the rows of one Folio into Elasticsearch documents of the shared search schema
 * (docs/search-indexes.md).
 *
 * Every item row becomes exactly one document, in one of two families chosen by its core:
 * text cores (newspaper articles, by default) go to the text family, every other core — objects,
 * archival documents, collections, people — to the item family. Nothing is duplicated per app,
 * per set or per run.
 *
 * The document is a fixed, typed core (identity, text, dates, media, links) plus `attrs`, one
 * `flattened` field carrying the folio's own facets. Those are the values the SQLite facet tables
 * hold ({@see FolioFtsIndexer::rowFacetValues()}), so both backends facet a folio identically.
 */
final class FolioSearchProjector
{
    public const string FAMILY_ITEM = 'item';
    public const string FAMILY_TEXT = 'text';

    /** Cores whose rows are people or organisations, not things a search is for. */
    private const array AGENT_CORES = ['per', 'org', 'creator', 'contributor'];
    private const array COLLECTION_CORES = ['coll', 'series'];
    private const array IMAGE_FIELDS = ['thumbnailUrl', 'largeImageUrl', 'iiifBase', 'displayImageUrl'];

    /** Built-in facets that already have typed fields of their own. */
    private const array TYPED_FACETS = ['core', 'dtoType'];

    /**
     * @param list<string> $textCores survos_folio.search.text_cores
     */
    public function __construct(
        private readonly FolioFtsIndexer $fts,
        private readonly array $textCores = ['article'],
    ) {
    }

    public function familyOf(string $coreCode): string
    {
        return in_array($coreCode, $this->textCores, true) ? self::FAMILY_TEXT : self::FAMILY_ITEM;
    }

    /** @return list<string> */
    public function textCores(): array
    {
        return $this->textCores;
    }

    /**
     * @return \Generator<int, array{family: string, id: string, doc: array<string, mixed>}>
     */
    public function documents(\PDO $pdo, string $folioCode, string $build): \Generator
    {
        $searchable = $this->fts->searchableProperties($pdo);
        $facetFields = array_values(array_diff($this->fts->facetFields($pdo), self::TYPED_FACETS));
        // Pages and links are merge-joined in row-id order, never loaded into memory: a newspaper
        // folio has a page and an issue link for each of its million articles.
        // SQLite's bare-column rule: with MIN(seq), url comes from the row's first page.
        $pages = $this->sortedCursor($pdo, 'page', 'SELECT row_id, COUNT(*), GROUP_CONCAT(DISTINCT type), url, MIN(seq) FROM page GROUP BY row_id ORDER BY row_id');
        // Link ends are local ids; item ids carry the folio code the file was built under.
        $first = (string) $pdo->query('SELECT id FROM item LIMIT 1')->fetchColumn();
        $prefix = false !== ($colon = strpos($first, ':')) ? substr($first, 0, $colon) : $folioCode;
        $links = $this->sortedCursor($pdo, 'link', "SELECT :folio || ':' || left_core || ':' || left_id AS k, GROUP_CONCAT(:folio || ':' || right_core || ':' || right_id, char(31)) FROM link GROUP BY k ORDER BY k", ['folio' => $prefix]);
        [$provider, $dataset] = self::split($folioCode);

        $select = $pdo->query('SELECT id, local_id, label, dto_type, dto_data, extras, core_id FROM item ORDER BY id');
        if (!$select instanceof \PDOStatement) {
            throw new \RuntimeException(sprintf('Unable to read rows of folio %s.', $folioCode));
        }
        while ($row = $select->fetch(\PDO::FETCH_ASSOC)) {
            $rowId = (string) $row['id'];
            $coreCode = self::coreCode((string) $row['core_id']);
            $data = self::decode($row['dto_data']);
            $extras = self::decode($row['extras']);
            $family = $this->familyOf($coreCode);
            [$dateStart, $dateEnd] = self::dates($data['date'] ?? null);
            $year = self::year($data['year'] ?? null) ?? (null !== $dateStart ? (int) substr($dateStart, 0, 4) : null);
            $page = self::advance($pages, $rowId);
            $pageCount = null === $page ? 0 : (int) $page[1];
            $mediaKinds = null === $page || null === $page[2] ? [] : array_values(array_unique(explode(',', (string) $page[2])));
            $link = self::advance($links, $rowId);

            $attrs = [];
            foreach ($facetFields as $field) {
                $values = $this->fts->rowFacetValues($field, $row, $data);
                if ([] !== $values) {
                    // flattened reads dots as nesting; a facet name is one key.
                    $attrs[str_replace('.', '_', $field)] = 1 === count($values) ? $values[0] : $values;
                }
            }

            $doc = [
                'rowId' => $rowId,
                'folioCode' => $folioCode,
                'provider' => $provider,
                'dataset' => $dataset,
                'coreCode' => $coreCode,
                'localId' => (string) $row['local_id'],
                'dtoType' => self::str($row['dto_type']),
                'role' => self::role($family, $coreCode, self::str($row['dto_type'])),
                'build' => $build,
                'label' => self::str($row['label']),
                'description' => self::text($data['description'] ?? null),
                'body' => $this->fts->searchBody($row, $searchable),
                'year' => $year,
                'dateStart' => $dateStart,
                'dateEnd' => $dateEnd,
                'hasImage' => $pageCount > 0 || [] !== array_filter(array_map(static fn (string $f) => $data[$f] ?? null, self::IMAGE_FIELDS)),
                'pageCount' => $pageCount,
                'mediaKinds' => $mediaKinds,
                // The first page's URL (often our s3:// mirror): what a hit card shows. Readers decide
                // whether it is renderable; dto image fields are provenance and stay in SQLite.
                'thumbnailUrl' => null === $page ? null : self::str($page[3]),
                'links' => null === $link ? [] : array_values(array_unique(explode("\x1F", (string) $link[1]))),
                'attrs' => $attrs,
            ];
            if (self::FAMILY_TEXT === $family) {
                $issueId = self::str($extras['issueId'] ?? null);
                $doc += [
                    'parentRowId' => null !== $issueId ? $folioCode.':doc:'.$issueId : null,
                    'issueId' => $issueId,
                    'articleTier' => self::str($extras['articleTier'] ?? null),
                    'recordKind' => self::str($extras['recordKind'] ?? null),
                    'pageSeq' => is_numeric($extras['pdfPage'] ?? null) ? (int) $extras['pdfPage'] : null,
                ];
            }

            yield ['family' => $family, 'id' => self::documentId($rowId), 'doc' => array_filter($doc, static fn (mixed $v): bool => null !== $v && '' !== $v && [] !== $v)];
        }
    }

    /**
     * Expected documents per family, straight from SQLite: what verification compares ES against.
     *
     * @return array{item: int, text: int, cores: array<string, int>}
     */
    public function expectedCounts(\PDO $pdo): array
    {
        $counts = ['item' => 0, 'text' => 0, 'cores' => []];
        foreach ($pdo->query('SELECT core_id, COUNT(*) FROM item GROUP BY core_id')->fetchAll(\PDO::FETCH_NUM) as [$coreId, $n]) {
            $core = self::coreCode((string) $coreId);
            $counts['cores'][$core] = (int) $n;
            $counts[$this->familyOf($core)] += (int) $n;
        }

        return $counts;
    }

    /**
     * Which attrs a reader should aggregate for a (core, dtoType): the folio's declared facets, so
     * a search page never opens SQLite to draw its sidebar.
     *
     * @return list<array{name: string, label: ?string, type: ?string, core: ?string, dtoType: ?string}>
     */
    public function facetCatalog(\PDO $pdo): array
    {
        $fields = array_flip(array_diff($this->fts->facetFields($pdo), self::TYPED_FACETS));
        if (!self::tableExists($pdo, 'schema_property') || !self::tableExists($pdo, 'schema_table')) {
            return array_map(static fn (string $name) => ['name' => $name, 'label' => null, 'type' => null, 'core' => null, 'dtoType' => null], array_keys($fields));
        }
        $catalog = [];
        foreach ($pdo->query(<<<'SQL'
            SELECT DISTINCT p.name, p.label, p.type, t.core_code, t.dto_type
            FROM schema_property p JOIN schema_table t ON t.id = p.table_id
            WHERE p.visible = 1 AND (p.facet = 1 OR p.filterable = 1)
            ORDER BY t.core_code, t.dto_type, p.position, p.name
            SQL)->fetchAll(\PDO::FETCH_NUM) as [$name, $label, $type, $core, $dtoType]) {
            if (isset($fields[$name])) {
                $catalog[] = ['name' => str_replace('.', '_', (string) $name), 'label' => $label, 'type' => $type, 'core' => $core, 'dtoType' => $dtoType];
            }
        }

        return $catalog;
    }

    /** ES ids are capped at 512 bytes; row ids almost never come close, and readable ids are worth keeping. */
    public static function documentId(string $rowId): string
    {
        return strlen($rowId) <= 500 ? $rowId : 'sha256:'.hash('sha256', $rowId);
    }

    /**
     * A partial or ranged date as an inclusive ISO interval: "1953" → 1953-01-01..1953-12-31,
     * "1941-12" → the month, "1940-1945" → both years. Null when nothing date-like is there.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function dates(mixed $value): array
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if (!is_scalar($value) || '' === trim((string) $value)) {
            return [null, null];
        }
        $value = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return [$m[0], $m[0]];
        }
        if (preg_match('/^(\d{4})-(\d{2})(?!\d)/', $value, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $start = sprintf('%s-%s-01', $m[1], $m[2]);

            return [$start, date('Y-m-t', strtotime($start))];
        }
        if (preg_match('/^(\d{4})\s*(?:-|–|to|\/)\s*(\d{4})$/u', $value, $m) && $m[2] >= $m[1]) {
            return [$m[1].'-01-01', $m[2].'-12-31'];
        }
        if (preg_match('/(?<!\d)(\d{4})(?!\d)/', $value, $m)) {
            return [$m[1].'-01-01', $m[1].'-12-31'];
        }

        return [null, null];
    }

    /** "<provider>/<dataset>.<locale>": a translated artifact of the source folio. */
    public static function isTranslation(string $folioCode): bool
    {
        return 1 === preg_match('/\.[a-z]{2}(?:-[A-Za-z]+)?$/', $folioCode);
    }

    /** @return array{0: string, 1: string} */
    public static function split(string $folioCode): array
    {
        $base = preg_replace('/\.[a-z]{2}(?:-[A-Za-z]+)?$/', '', $folioCode) ?? $folioCode;
        [$provider, $dataset] = array_pad(explode('/', $base, 2), 2, '');

        return [$provider, $dataset];
    }

    private static function role(string $family, string $core, ?string $dtoType): string
    {
        return match (true) {
            self::FAMILY_TEXT === $family => 'text',
            in_array($core, self::AGENT_CORES, true), in_array($dtoType, ['person', 'org', 'organization'], true) => 'agent',
            in_array($core, self::COLLECTION_CORES, true), 'collection' === $dtoType => 'collection',
            default => 'record',
        };
    }

    /**
     * A query whose rows are sorted by their first column (a row id), or null when the table is absent.
     *
     * @param array<string, string> $params
     */
    private function sortedCursor(\PDO $pdo, string $table, string $sql, array $params = []): ?\PDOStatement
    {
        if (!self::tableExists($pdo, $table)) {
            return null;
        }
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * Advance a sorted cursor to $rowId and return its row there, if any. Items are read ORDER BY id
     * with the same binary collation, so each cursor only ever moves forward.
     *
     * @param-out ?\PDOStatement $cursor
     *
     * @return list<mixed>|null
     */
    private static function advance(?\PDOStatement &$cursor, string $rowId): ?array
    {
        static $heads = new \WeakMap();
        if (null === $cursor) {
            return null;
        }
        $head = $heads[$cursor] ?? ($heads[$cursor] = $cursor->fetch(\PDO::FETCH_NUM));
        while (false !== $head && strcmp((string) $head[0], $rowId) < 0) {
            $head = $heads[$cursor] = $cursor->fetch(\PDO::FETCH_NUM);
        }
        if (false === $head) {
            $cursor = null;

            return null;
        }

        return 0 === strcmp((string) $head[0], $rowId) ? $head : null;
    }

    private static function coreCode(string $coreId): string
    {
        return false === ($at = strrpos($coreId, ':')) ? $coreId : substr($coreId, $at + 1);
    }

    private static function year(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= -9999 && (int) $value <= 9999 ? (int) $value : null;
    }

    private static function str(mixed $value): ?string
    {
        return is_scalar($value) && '' !== trim((string) $value) ? trim((string) $value) : null;
    }

    private static function text(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode("\n", array_filter($value, 'is_scalar'));
        }

        return self::str($value);
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $json): array
    {
        if (!is_string($json) || '' === $json) {
            return [];
        }
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function tableExists(\PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $statement->execute([$table]);

        return false !== $statement->fetchColumn();
    }
}
