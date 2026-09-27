<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Psr\Log\LoggerInterface;
use Survos\FolioBundle\Entity\Folio;

/**
 * Text search for folios that have no FTS index, in one Elasticsearch index shared by every folio.
 *
 * A dataset that declares `extras.search: {backend: elasticsearch, allowFtsSkip: true}` is built
 * without `item_fts` (docs/search-policy.md). Its rows are indexed here instead, with the same
 * words FTS would have held ({@see FolioFtsIndexer::searchBody()}), and {@see match()} answers a
 * text query with row ids, best first. Everything else about the search — scope, filters, facets,
 * hits, thumbnails — stays in SQLite: the adapter treats the ids as the match (textMatcher).
 *
 * One index for all folios, filtered by folioCode, and not per-app prefixed: folios are shared
 * files that harvest builds and zm, Ink and fotostory read, so their index is shared the same way.
 * Documents are keyed by the row's composite id (folioCode:core:localId), which survives rebuilds;
 * rowids do not.
 */
final class FolioElasticRowIndex
{
    /** Rows per _bulk request. */
    private const int CHUNK = 1000;

    /** A down node must cost a search page this long at most, not the client's default. */
    private const float MATCH_TIMEOUT = 5.0;

    public function __construct(
        private readonly FolioService $folios,
        private readonly FolioFtsIndexer $fts,
        private readonly FolioElasticClient $elastic,
        /** survos_folio.elastic_row_index */
        private readonly string $index = 'folio_row',
        /** survos_folio.elastic_match_limit: how many ids a text query brings back to SQLite. */
        private readonly int $matchLimit = 1000,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->elastic->isConfigured();
    }

    /** Whether a folio's build left text search to this index (fts_content = off). */
    public function wantsIndex(string $dbFile): bool
    {
        return FolioFtsIndexer::ftsContent($this->open($dbFile)) === Folio::FTS_CONTENT_OFF;
    }

    /**
     * (Re)index every row of one folio. Rows are written under a fresh build stamp and the folio's
     * rows from earlier stamps are deleted afterwards, so a search during a reindex sees the old
     * rows or the new ones and never an empty folio.
     *
     * @param (callable(int): void)|null $progress called with the running row count after each chunk
     * @return array{rows: int, bytes: int, removed: int}
     */
    public function index(string $folioCode, ?callable $progress = null): array
    {
        $this->ensureIndex();
        $pdo = $this->open($this->folios->path($folioCode));
        $properties = $this->fts->searchableProperties($pdo);
        $stamp = (string) (int) (microtime(true) * 1000);

        $select = $pdo->query('SELECT id, local_id, label, dto_type, dto_data, extras, core_id FROM item ORDER BY rowid');
        if (!$select instanceof \PDOStatement) {
            throw new \RuntimeException(sprintf('Unable to read rows of folio %s.', $folioCode));
        }
        $rows = 0;
        $bytes = 0;
        $buffer = [];
        while ($row = $select->fetch(\PDO::FETCH_ASSOC)) {
            $body = $this->fts->searchBody($row, $properties);
            $buffer[] = json_encode(['index' => ['_index' => $this->index, '_id' => $row['id']]], JSON_THROW_ON_ERROR);
            $buffer[] = json_encode([
                'folioCode' => $folioCode,
                'coreCode' => substr((string) $row['core_id'], strrpos((string) $row['core_id'], ':') + 1),
                'label' => $row['label'],
                'body' => $body,
                'build' => $stamp,
            ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            ++$rows;
            $bytes += strlen($body);
            if (count($buffer) >= self::CHUNK * 2) {
                $this->flush($buffer);
                $buffer = [];
                $progress && $progress($rows);
            }
        }
        if ($buffer !== []) {
            $this->flush($buffer);
            $progress && $progress($rows);
        }
        $this->elastic->request('POST', $this->index.'/_refresh');
        $removed = $this->deleteWhere(['bool' => [
            'filter' => [['term' => ['folioCode' => $folioCode]]],
            'must_not' => [['term' => ['build' => $stamp]]],
        ]]);

        return ['rows' => $rows, 'bytes' => $bytes, 'removed' => $removed];
    }

    /** Drop a folio's rows, e.g. once it has an FTS index again. */
    public function remove(string $folioCode): int
    {
        return $this->deleteWhere(['term' => ['folioCode' => $folioCode]]);
    }

    /** Rows indexed for a folio; null when the node or the index is not there. */
    public function count(string $folioCode): ?int
    {
        try {
            $response = $this->elastic->request('POST', $this->index.'/_count', ['query' => ['term' => ['folioCode' => $folioCode]]],
                expected: [404], decode: true, timeout: self::MATCH_TIMEOUT);
        } catch (\Throwable $e) {
            $this->logger?->warning('Folio row index unreachable', ['folio' => $folioCode, 'error' => $e->getMessage()]);

            return null;
        }

        return isset($response['count']) ? (int) $response['count'] : null;
    }

    /**
     * Row ids matching a text query in one folio, best first, at most elastic_match_limit of them.
     *
     * Null when this index cannot answer — node down, index missing, or the folio not indexed
     * (yet) — so the caller can fall back rather than report "no matches" for rows it never saw.
     * An indexed folio with no match is an empty list.
     *
     * @return list<string>|null
     */
    public function match(string $folioCode, string $text, ?string $coreCode = null): ?array
    {
        $filter = [['term' => ['folioCode' => $folioCode]]];
        if ($coreCode !== null) {
            $filter[] = ['term' => ['coreCode' => $coreCode]];
        }
        try {
            $response = $this->elastic->request('POST', $this->index.'/_search', [
                'size' => $this->matchLimit,
                '_source' => false,
                'track_total_hits' => false,
                'query' => ['bool' => [
                    'filter' => $filter,
                    'must' => [['simple_query_string' => [
                        'query' => self::queryString($text),
                        'fields' => ['label^2', 'body'],
                        'default_operator' => 'and',
                    ]]],
                ]],
            ], expected: [404], decode: true, timeout: self::MATCH_TIMEOUT);
        } catch (\Throwable $e) {
            $this->logger?->warning('Folio row index unreachable', ['folio' => $folioCode, 'error' => $e->getMessage()]);

            return null;
        }
        if (!isset($response['hits']['hits'])) {
            return null; // 404: no index at all
        }
        $ids = array_map(static fn (array $hit): string => (string) $hit['_id'], $response['hits']['hits']);
        // No match in a folio that was never indexed is not "no match".
        if ($ids === [] && !$this->count($folioCode)) {
            return null;
        }

        return $ids;
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

    private function ensureIndex(): void
    {
        if ($this->elastic->request('HEAD', $this->index, expected: [200, 404]) === 200) {
            return;
        }
        // `english` stems and drops stopwords, as the FTS path's porter tokenizer does; label is
        // text too so a title hit can outrank the same words deep in an OCR body.
        $this->elastic->request('PUT', $this->index, [
            'settings' => ['number_of_replicas' => 0],
            'mappings' => [
                'dynamic' => 'strict',
                'properties' => [
                    'folioCode' => ['type' => 'keyword'],
                    'coreCode' => ['type' => 'keyword'],
                    'build' => ['type' => 'keyword'],
                    'label' => ['type' => 'text', 'analyzer' => 'english'],
                    'body' => ['type' => 'text', 'analyzer' => 'english'],
                ],
            ],
        ]);
    }

    /** @param list<string> $buffer NDJSON lines, action and document alternating */
    private function flush(array $buffer): void
    {
        $response = $this->elastic->request('POST', '_bulk', raw: implode("\n", $buffer)."\n", decode: true,
            headers: ['Content-Type' => 'application/x-ndjson']);
        // _bulk answers 200 even when documents were rejected; a silent partial index would read
        // as rows that match nothing.
        if ($response['errors'] ?? false) {
            foreach ($response['items'] ?? [] as $item) {
                if (isset($item['index']['error'])) {
                    throw new \RuntimeException(sprintf('Elasticsearch rejected %s: %s', $item['index']['_id'] ?? '?',
                        $item['index']['error']['reason'] ?? json_encode($item['index']['error'])));
                }
            }
        }
    }

    /** @param array<string, mixed> $query */
    private function deleteWhere(array $query): int
    {
        $response = $this->elastic->request('POST', $this->index.'/_delete_by_query?refresh=true&conflicts=proceed',
            ['query' => $query], expected: [404], decode: true);

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
