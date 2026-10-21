<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Psr\Log\LoggerInterface;

/**
 * The shared Elasticsearch search indexes for every published Folio (docs/search-indexes.md).
 *
 * Two families plus a catalog, none per app, per set or per folio:
 *  - item (`folio_item`): one document per catalog row — objects, archival records, issues, people;
 *  - text (`folio_text`): one document per text unit — newspaper articles — pointing at its parent;
 *  - meta (`folio_meta`): one document per folio — its build, row counts and declared facets.
 *
 * Harvest is the only writer. A folio is (re)indexed under its build stamp, stale rows of earlier
 * builds are deleted, and {@see verify()} then has to find exactly the folio's SQLite row count
 * for that build in each family. Only then is the meta document marked complete.
 */
final class FolioSearchIndex
{
    public const int SCHEMA_VERSION = 1;

    /** Rows per _bulk request, unless the request reaches BULK_BYTES first (long OCR articles). */
    private const int CHUNK = 1000;
    private const int BULK_BYTES = 8_000_000;
    private const float READ_TIMEOUT = 5.0;

    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioSearchProjector $projector,
        private readonly FolioElasticClient $elastic,
        /** survos_folio.search.item_index / text_index / meta_index */
        private readonly string $itemIndex = 'folio_item',
        private readonly string $textIndex = 'folio_text',
        private readonly string $metaIndex = 'folio_meta',
        private readonly int $shards = 1,
        private readonly int $replicas = 0,
        private readonly int $matchLimit = 1000,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->elastic->isConfigured();
    }

    /** @return array{item: string, text: string, meta: string} */
    public function indexNames(): array
    {
        return ['item' => $this->itemIndex, 'text' => $this->textIndex, 'meta' => $this->metaIndex];
    }

    /**
     * The build a folio file on disk represents: changes whenever the published file is replaced.
     * Deterministic, so a reader or an audit can tell from the file alone whether ES is current.
     */
    public function buildStamp(string $folioCode): string
    {
        $path = $this->folios->path($folioCode);
        clearstatcache(true, $path);
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf('No folio at %s.', $path));
        }

        return substr(hash('sha256', $folioCode."\0".filesize($path)."\0".filemtime($path)), 0, 20);
    }

    /**
     * (Re)index every row of one folio, then verify it.
     *
     * Document ids are the row ids, so a rebuild overwrites in place and a row is never indexed
     * twice; rows that disappeared from the folio are the ones left on an older build stamp.
     *
     * @param (callable(int): void)|null $progress called with the running row count after each chunk
     *
     * @return array{rows: int, item: int, text: int, bytes: int, removed: int, build: string, verify: array<string, mixed>}
     */
    public function index(string $folioCode, ?callable $progress = null): array
    {
        if (FolioSearchProjector::isTranslation($folioCode)) {
            throw new \InvalidArgumentException(sprintf('%s is a translation: it shares its source\'s row ids and is not indexed on its own (docs/search-indexes.md).', $folioCode));
        }
        // Every writer on this host shares the lock; deploy one writer host (Harvest).
        $lock = fopen(sys_get_temp_dir().'/folio-search-'.hash('sha256', $this->itemIndex.':'.$folioCode).'.lock', 'c');
        if (false === $lock) {
            throw new \RuntimeException('Cannot open Folio index lock.');
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new \RuntimeException('Another index run is active for '.$folioCode);
        }
        try {
            return $this->indexLocked($folioCode, $progress);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Is this folio's current build fully and only in ES? Compares per family:
     * SQLite rows vs ES docs on the current build, and counts docs left on older builds.
     *
     * @return array{folio: string, status: string, build: ?string, expected: array{item: int, text: int}, indexed: array{item: ?int, text: ?int}, stale: ?int, meta: ?string}
     */
    public function verify(string $folioCode): array
    {
        $result = ['folio' => $folioCode, 'status' => 'ok', 'build' => null, 'expected' => ['item' => 0, 'text' => 0], 'indexed' => ['item' => null, 'text' => null], 'stale' => null, 'meta' => null];
        try {
            $build = $this->buildStamp($folioCode);
        } catch (\RuntimeException) {
            return ['status' => 'no-folio'] + $result;
        }
        $expected = $this->projector->expectedCounts($this->open($this->folios->path($folioCode)));
        $result['build'] = $build;
        $result['expected'] = ['item' => $expected['item'], 'text' => $expected['text']];
        $current = ['bool' => ['filter' => [['term' => ['folioCode' => $folioCode]], ['term' => ['build' => $build]]]]];
        $older = ['bool' => ['filter' => [['term' => ['folioCode' => $folioCode]]], 'must_not' => [['term' => ['build' => $build]]]]];
        $result['indexed']['item'] = $this->count($this->itemIndex, $current);
        $result['indexed']['text'] = $this->count($this->textIndex, $current);
        $staleItem = $this->count($this->itemIndex, $older);
        $staleText = $this->count($this->textIndex, $older);
        $result['stale'] = null === $staleItem || null === $staleText ? null : $staleItem + $staleText;
        $meta = $this->meta($folioCode);
        $result['meta'] = null === $meta ? null : (($meta['build'] ?? null) === $build && ($meta['complete'] ?? false) ? 'current' : 'outdated');

        $result['status'] = match (true) {
            null === $result['indexed']['item'] || null === $result['indexed']['text'] => 'unreachable',
            // An empty folio is fully indexed once its meta document is current.
            0 === $result['expected']['item'] + $result['expected']['text'] => 0 < (int) $result['stale'] ? 'stale' : (($meta['build'] ?? null) === $build ? 'ok' : 'missing'),
            0 === $result['indexed']['item'] + $result['indexed']['text'] && 0 === (int) $result['stale'] => 'missing',
            $result['indexed'] !== $result['expected'] => 0 < (int) $result['stale'] ? 'stale' : 'partial',
            0 < (int) $result['stale'] => 'stale',
            // Every row is there, but written by code that projected it differently. Meta written
            // before documentVersion existed is version 1.
            ($meta['documentVersion'] ?? 1) !== FolioSearchProjector::DOCUMENT_VERSION => 'outdated',
            default => 'ok',
        };

        return $result;
    }

    /** Drop a folio from every family. */
    public function remove(string $folioCode): int
    {
        $removed = 0;
        foreach ([$this->itemIndex, $this->textIndex] as $index) {
            $removed += $this->deleteWhere($index, ['term' => ['folioCode' => $folioCode]]);
        }
        $this->elastic->request('DELETE', $this->metaIndex.'/_doc/'.rawurlencode($folioCode).'?refresh=true', expected: [404]);

        return $removed;
    }

    /** @return array<string, mixed>|null the folio's meta document */
    public function meta(string $folioCode): ?array
    {
        try {
            $response = $this->elastic->request('GET', $this->metaIndex.'/_doc/'.rawurlencode($folioCode), expected: [404], decode: true, timeout: self::READ_TIMEOUT);
        } catch (\Throwable $e) {
            $this->logger?->warning('Folio search index unreachable', ['folio' => $folioCode, 'error' => $e->getMessage()]);

            return null;
        }

        return ($response['found'] ?? false) ? $response['_source'] : null;
    }

    /**
     * Row ids matching a text query in one folio, best first — the drop-in for SQLite FTS on a
     * folio built without it. Null when ES cannot answer for this folio (down, or not indexed).
     *
     * @return list<string>|null
     */
    public function match(string $folioCode, string $text, ?string $coreCode = null): ?array
    {
        if (null === $this->meta($folioCode)) {
            return null;
        }
        $filter = [['term' => ['folioCode' => $folioCode]]];
        if (null !== $coreCode) {
            $filter[] = ['term' => ['coreCode' => $coreCode]];
        }
        try {
            $response = $this->search([
                'size' => $this->matchLimit,
                '_source' => ['rowId'],
                'track_total_hits' => false,
                'query' => ['bool' => ['filter' => $filter, 'must' => [self::textQuery($text)]]],
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning('Folio search index unreachable', ['folio' => $folioCode, 'error' => $e->getMessage()]);

            return null;
        }

        return array_map(static fn (array $hit): string => (string) $hit['_source']['rowId'], $response['hits']['hits'] ?? []);
    }

    /**
     * A raw search across the families (default: both), for readers composing their own query.
     *
     * @param array<string, mixed> $body
     * @param list<string>|null    $families item and/or text
     *
     * @return array<string, mixed>
     */
    public function search(array $body, ?array $families = null): array
    {
        $names = array_map(fn (string $family): string => match ($family) {
            FolioSearchProjector::FAMILY_ITEM => $this->itemIndex,
            FolioSearchProjector::FAMILY_TEXT => $this->textIndex,
        }, $families ?? [FolioSearchProjector::FAMILY_ITEM, FolioSearchProjector::FAMILY_TEXT]);

        return $this->elastic->request('POST', implode(',', $names).'/_search?ignore_unavailable=true', $body, decode: true, timeout: 30);
    }

    /** @return array<string, mixed> the box's words as a simple_query_string over label and body */
    public static function textQuery(string $text): array
    {
        return ['simple_query_string' => [
            'query' => self::queryString($text),
            'fields' => ['label^3', 'description^2', 'body'],
            'default_operator' => 'and',
        ]];
    }

    /**
     * The search box's words in simple_query_string syntax. That syntax already reads quotes,
     * `*` prefixes and parentheses the way the FTS path does; only and/or/not are spelled as words
     * there, which simple_query_string would search for, so they become + | -.
     */
    public static function queryString(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '#')) {
            $text = trim(substr($text, 1)); // the FTS path's raw escape hatch has no meaning here
        }
        $text = (string) preg_replace('/(?<=\s)(?:and)(?=\s)/i', '+', $text);
        $text = (string) preg_replace('/(?<=\s)(?:or)(?=\s)/i', '|', $text);

        return (string) preg_replace('/(?:(?<=\s)|^)not\s+/i', '-', $text);
    }

    /** The one mapping both families share; text-only fields are simply absent on items. */
    public static function mapping(): array
    {
        $keyword = ['type' => 'keyword', 'ignore_above' => 1024];
        $date = ['type' => 'date', 'format' => 'strict_date||yyyy-MM||yyyy', 'ignore_malformed' => true];

        return [
            'dynamic' => 'strict',
            '_meta' => ['folioSearchVersion' => self::SCHEMA_VERSION],
            // body is rebuilt from the folio on every index run and never shown from ES: keeping it
            // out of _source roughly halves the size of the text family.
            '_source' => ['excludes' => ['body']],
            'properties' => [
                'rowId' => $keyword, 'folioCode' => $keyword, 'provider' => $keyword, 'dataset' => $keyword,
                'coreCode' => $keyword, 'localId' => $keyword, 'dtoType' => $keyword, 'role' => $keyword,
                'build' => $keyword,
                'label' => ['type' => 'text', 'fields' => ['sort' => ['type' => 'keyword', 'ignore_above' => 256]]],
                'description' => ['type' => 'text'],
                'body' => ['type' => 'text'],
                'year' => ['type' => 'integer'],
                'dateStart' => $date, 'dateEnd' => $date,
                'hasImage' => ['type' => 'boolean'],
                'pageCount' => ['type' => 'integer'],
                'imageCount' => ['type' => 'integer'],
                'mediaKinds' => $keyword,
                'license' => $keyword,
                'thumbnailUrl' => ['type' => 'keyword', 'index' => false, 'doc_values' => false],
                'links' => $keyword,
                'parentRowId' => $keyword, 'issueId' => $keyword, 'articleTier' => $keyword, 'recordKind' => $keyword,
                'pageSeq' => ['type' => 'integer'],
                'attrs' => ['type' => 'flattened', 'ignore_above' => 512],
            ],
        ];
    }

    public function ensureIndexes(): void
    {
        foreach ([$this->itemIndex, $this->textIndex] as $index) {
            $this->ensure($index, ['number_of_shards' => $this->shards, 'number_of_replicas' => $this->replicas], self::mapping());
        }
        $this->ensure($this->metaIndex, ['number_of_shards' => 1, 'number_of_replicas' => $this->replicas], [
            'dynamic' => 'strict',
            '_meta' => ['folioSearchVersion' => self::SCHEMA_VERSION],
            'properties' => [
                'folioCode' => ['type' => 'keyword'], 'build' => ['type' => 'keyword'],
                'complete' => ['type' => 'boolean'], 'documentVersion' => ['type' => 'integer'], 'indexedAt' => ['type' => 'date'],
                'rows' => ['type' => 'integer'], 'item' => ['type' => 'integer'], 'text' => ['type' => 'integer'],
                'cores' => ['type' => 'object', 'enabled' => false],
                'facets' => ['type' => 'object', 'enabled' => false],
            ],
        ]);
    }

    private function indexLocked(string $folioCode, ?callable $progress): array
    {
        $this->ensureIndexes();
        $build = $this->buildStamp($folioCode);
        $pdo = $this->open($this->folios->path($folioCode));
        $names = [FolioSearchProjector::FAMILY_ITEM => $this->itemIndex, FolioSearchProjector::FAMILY_TEXT => $this->textIndex];

        $rows = 0;
        $counts = [FolioSearchProjector::FAMILY_ITEM => 0, FolioSearchProjector::FAMILY_TEXT => 0];
        $bytes = 0;
        $buffer = [];
        $bufferBytes = 0;
        foreach ($this->projector->documents($pdo, $folioCode, $build) as ['family' => $family, 'id' => $id, 'doc' => $doc]) {
            $action = json_encode(['index' => ['_index' => $names[$family], '_id' => $id]], JSON_THROW_ON_ERROR);
            $source = json_encode($doc, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $buffer[] = $action;
            $buffer[] = $source;
            $bufferBytes += strlen($source);
            ++$rows;
            ++$counts[$family];
            $bytes += strlen((string) ($doc['body'] ?? ''));
            if (count($buffer) >= self::CHUNK * 2 || $bufferBytes >= self::BULK_BYTES) {
                $this->flush($buffer);
                $buffer = [];
                $bufferBytes = 0;
                $progress && $progress($rows);
            }
        }
        if ([] !== $buffer) {
            $this->flush($buffer);
            $progress && $progress($rows);
        }
        $this->elastic->request('POST', $this->itemIndex.','.$this->textIndex.'/_refresh');
        $removed = 0;
        foreach ($names as $index) {
            $removed += $this->deleteWhere($index, ['bool' => [
                'filter' => [['term' => ['folioCode' => $folioCode]]],
                'must_not' => [['term' => ['build' => $build]]],
            ]]);
        }

        $expected = $this->projector->expectedCounts($pdo);
        $this->writeMeta($folioCode, $build, $expected, $this->projector->facetCatalog($pdo), complete: false);
        $verify = $this->verify($folioCode);
        if ('ok' !== $verify['status']) {
            throw new \RuntimeException(sprintf('Folio %s did not verify after indexing: %s (expected %s, indexed %s, stale %s).', $folioCode, $verify['status'], json_encode($verify['expected']), json_encode($verify['indexed']), $verify['stale'] ?? '?'));
        }
        $this->writeMeta($folioCode, $build, $expected, $this->projector->facetCatalog($pdo), complete: true);

        return ['rows' => $rows, 'item' => $counts['item'], 'text' => $counts['text'], 'bytes' => $bytes, 'removed' => $removed, 'build' => $build, 'verify' => $verify];
    }

    /** @param array{item: int, text: int, cores: array<string, int>} $expected */
    private function writeMeta(string $folioCode, string $build, array $expected, array $facets, bool $complete): void
    {
        $this->elastic->request('PUT', $this->metaIndex.'/_doc/'.rawurlencode($folioCode).'?refresh=true', [
            'folioCode' => $folioCode,
            'build' => $build,
            'complete' => $complete,
            'documentVersion' => FolioSearchProjector::DOCUMENT_VERSION,
            'indexedAt' => gmdate('c'),
            'rows' => $expected['item'] + $expected['text'],
            'item' => $expected['item'],
            'text' => $expected['text'],
            'cores' => (object) $expected['cores'],
            'facets' => ['fields' => $facets],
        ]);
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $mappings
     */
    private function ensure(string $index, array $settings, array $mappings): void
    {
        if (200 === $this->elastic->request('HEAD', $index, expected: [200, 404])) {
            foreach ($this->elastic->request('GET', $index.'/_mapping', decode: true) as $definition) {
                if (($definition['mappings']['_meta']['folioSearchVersion'] ?? null) !== self::SCHEMA_VERSION) {
                    throw new \RuntimeException(sprintf('Index %s has another Folio search schema version; create the new version under a new name, reindex, then switch readers.', $index));
                }
            }
            // Within a version fields are only ever added: ES accepts new ones on a live index (and
            // rejects a changed one, which would be a new version). Rows get them when reindexed.
            $this->elastic->request('PUT', $index.'/_mapping', ['properties' => $mappings['properties']]);

            return;
        }
        $this->elastic->request('PUT', $index, ['settings' => $settings, 'mappings' => $mappings]);
    }

    /** @param array<string, mixed> $query */
    private function count(string $index, array $query): ?int
    {
        try {
            $response = $this->elastic->request('POST', $index.'/_count', ['query' => $query], expected: [404], decode: true, timeout: self::READ_TIMEOUT);
        } catch (\Throwable $e) {
            $this->logger?->warning('Folio search index unreachable', ['index' => $index, 'error' => $e->getMessage()]);

            return null;
        }

        return isset($response['count']) ? (int) $response['count'] : (404 === ($response['status'] ?? null) ? 0 : null);
    }

    /** @param list<string> $buffer NDJSON lines, action and document alternating */
    private function flush(array $buffer): void
    {
        $response = $this->elastic->request('POST', '_bulk', raw: implode("\n", $buffer)."\n", decode: true,
            headers: ['Content-Type' => 'application/x-ndjson']);
        // _bulk answers 200 even when documents were rejected.
        if ($response['errors'] ?? false) {
            foreach ($response['items'] ?? [] as $item) {
                if (isset($item['index']['error'])) {
                    throw new \RuntimeException(sprintf('Elasticsearch rejected %s: %s', $item['index']['_id'] ?? '?', $item['index']['error']['reason'] ?? json_encode($item['index']['error'])));
                }
            }
        }
    }

    /** @param array<string, mixed> $query */
    private function deleteWhere(string $index, array $query): int
    {
        $response = $this->elastic->request('POST', $index.'/_delete_by_query?refresh=true&conflicts=proceed',
            ['query' => $query], expected: [404], decode: true);
        if (($response['timed_out'] ?? false) || ($response['failures'] ?? []) !== [] || ($response['version_conflicts'] ?? 0) > 0) {
            throw new \RuntimeException('Folio stale-document cleanup was incomplete; retry indexing.');
        }

        return (int) ($response['deleted'] ?? 0);
    }

    private function open(string $dbFile): \PDO
    {
        if (!is_file($dbFile)) {
            throw new \RuntimeException(sprintf('No folio at %s.', $dbFile));
        }

        return new \PDO('sqlite:'.$dbFile, options: [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY,
        ]);
    }
}
