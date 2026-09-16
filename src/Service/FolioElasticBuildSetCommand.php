<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pool several folios into ONE combined Elasticsearch index — the twin of
 * {@see FolioMeiliBuildSetCommand}, for apps that have dropped Meilisearch.
 *
 * A folio's FTS5 index is per file, so searching one folio is a local query and searching across
 * folios is not. That is the only reason a pooled index exists, and it is why this is not covered
 * by elastic-bundle: `ElasticIndexService` indexes Doctrine entity classes discovered from
 * `EntityMetaRegistry`, and these documents are rows out of SQLite files with no entity behind
 * them. Nothing in that path can be pointed at a folio.
 *
 * Documents stream from {@see FolioDocumentStream} — the same source the Meilisearch build uses,
 * with the same `--fields` projection and `--extras` lift — straight into the `_bulk` endpoint as
 * NDJSON. No intermediate file, and one bad folio is skipped rather than aborting the run.
 *
 * Talks to Elasticsearch over plain HTTP rather than through `elasticsearch/elasticsearch`. The
 * bulk API is newline-delimited JSON over POST, which is what the Meilisearch path already does,
 * and folio-bundle is installed in applications that have no Elasticsearch client at all. Adding
 * one as a hard dependency to make one command work is the wrong trade.
 *
 * ## Why an explicit mapping
 *
 * Meilisearch infers everything and is told separately which attributes are filterable.
 * Elasticsearch maps a string to `text` by default, which is analysed and cannot be aggregated —
 * so a facet on it fails, and the natural fix (`.keyword` subfields) leaves every facet name
 * different from the field name the application filters on. So the mapping is stated: the fields a
 * reader searches are `text`, everything else is `keyword`, and a facet is the field itself.
 */
#[AsCommand('folio:elastic:build-set', 'Index common fields from several folios into one combined Elasticsearch index.')]
final class FolioElasticBuildSetCommand
{
    /** Fields a reader searches for words in; everything else is an exact value. */
    private const array TEXT_FIELDS = [
        'title', 'label', 'description', 'caption', 'denseSummary', 'ocrText', 'subjects', 'tags',
    ];

    /** Documents per _bulk request. Large enough to be worth a round trip, small enough to retry. */
    private const int CHUNK = 500;

    /** Set from the DSN's ?ca= when the node uses a private CA, as fsn1's does. */
    private ?string $caBundle = null;

    public function __construct(
        private readonly FolioDocumentStream $stream,
        private readonly HttpClientInterface $http,
        #[Autowire('%env(default::ELASTICSEARCH_DSN)%')] private readonly ?string $dsn = null,
        #[Autowire('%env(default::SEARCH_INDEX_PREFIX)%')] private readonly ?string $prefix = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Base index name, e.g. ink_articles (the prefix is applied automatically)')] string $indexBase,
        #[Argument('Folio codes to pool, e.g. cron-america/sn95079246 news/rappnews')] array $folioCodes,
        #[Option('Core code inside each folio')] string $core = 'obj',
        #[Option('Comma-separated content fields to keep')] string $fields = 'title,description,caption,denseSummary,subjects,tags,country,city,year,donor',
        #[Option('Skip the --fields whitelist and index every dtoData key')] bool $allFields = false,
        #[Option('Comma-separated extras keys to lift onto each document and make filterable, e.g. issueId,articleTier,recordKind')] ?string $extras = null,
        #[Option('Delete the index before indexing')] bool $reset = false,
        #[Option('Which per-folio file variant to open — a locale, or omitted for each folio\'s default build')] ?string $openLocale = null,
        #[Option('Comma-separated dtoData.contentType allowlist; scans every core and filters per item')] ?string $contentTypes = null,
    ): int {
        if ($folioCodes === []) {
            $io->error('Provide at least one folio code.');

            return Command::INVALID;
        }
        if (($this->dsn ?? '') === '') {
            $io->error('Set ELASTICSEARCH_DSN, e.g. elasticsearch://127.0.0.1:9200');

            return Command::INVALID;
        }
        [$base, $headers] = $this->endpoint($this->dsn);
        $index = ($this->prefix ?? '').$indexBase;

        $keep = array_values(array_filter(array_map('trim', explode(',', $fields)), static fn (string $f): bool => $f !== ''));
        $extraKeys = $extras !== null
            ? array_values(array_filter(array_map('trim', explode(',', $extras)), static fn (string $k): bool => $k !== ''))
            : [];
        // Lifted extras are content, so they survive the whitelist as well as --all-fields.
        $keep = array_values(array_unique([...$keep, ...$extraKeys]));
        $contentTypeList = $contentTypes !== null
            ? array_values(array_filter(array_map('trim', explode(',', $contentTypes)), static fn (string $t): bool => $t !== ''))
            : null;

        $io->title($index);
        if ($reset) {
            $this->request('DELETE', $base.'/'.$index, $headers, expected: [200, 404]);
            $io->writeln('  index deleted');
        }
        $this->createIndex($base, $index, $headers, $keep, $extraKeys, $allFields);

        $report = new FolioDocumentStreamReport();
        $count = 0;
        $buffer = [];
        foreach ($this->stream->documents($folioCodes, $core, $allFields ? null : $keep, $openLocale, $contentTypeList, $extraKeys, $report) as $document) {
            // A folio row id is "folioCode:coreCode:localId", whose slashes and colons are legal
            // in an Elasticsearch _id but make for unreadable URLs; keep the original as rowId and
            // use the sanitised form the Meilisearch build already uses, so the two indexes hold
            // the same identifiers and can be compared document for document.
            $id = isset($document['id']) ? preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $document['id']) : null;
            if ($id === null) {
                continue;
            }
            $document['rowId'] ??= $document['id'];
            $document['id'] = $id;
            $buffer[] = json_encode(['index' => ['_index' => $index, '_id' => $id]], JSON_THROW_ON_ERROR);
            $buffer[] = json_encode($document, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            if (count($buffer) >= self::CHUNK * 2) {
                $count += $this->flush($io, $base, $headers, $buffer);
                $buffer = [];
            }
        }
        if ($buffer !== []) {
            $count += $this->flush($io, $base, $headers, $buffer);
        }

        // Without a refresh the documents are indexed but not yet searchable, which reads as an
        // empty index to whoever looks next.
        $this->request('POST', $base.'/'.$index.'/_refresh', $headers);

        foreach ($report->perFolio as $folioCode => $rows) {
            $io->writeln(sprintf('  %-34s %s row(s)', $folioCode, number_format($rows)));
        }
        foreach ($report->failed as $folioCode => $problem) {
            $io->writeln(sprintf('  <comment>%-34s %s</comment>', $folioCode, $problem));
        }
        $io->success(sprintf('Indexed %s document(s) from %d folio(s) into %s.',
            number_format($count), count($report->perFolio), $index));

        return $report->failed === [] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Create the index with a stated mapping, or leave an existing one alone.
     *
     * `dynamic_templates` catches the fields a pooled build cannot know in advance — `--all-fields`
     * indexes whatever each folio's DTO happens to carry — and maps them to `keyword`, which is
     * aggregatable. The searchable fields are named explicitly as `text`, because those are the
     * ones a reader types words into rather than filters on.
     *
     * @param list<string> $keep
     * @param list<string> $extraKeys
     * @param array<string, list<string>> $headers
     */
    private function createIndex(string $base, string $index, array $headers, array $keep, array $extraKeys, bool $allFields): void
    {
        $exists = $this->request('HEAD', $base.'/'.$index, $headers, expected: [200, 404]);
        if ($exists === 200) {
            return;
        }
        $properties = [];
        foreach (self::TEXT_FIELDS as $field) {
            if ($allFields || in_array($field, $keep, true) || $field === 'title' || $field === 'label') {
                $properties[$field] = ['type' => 'text'];
            }
        }
        // Identity and the lifted extras are exact values, and the extras are the whole reason the
        // facets work: articleTier is what lets one index answer both "the headlines" and "the
        // full articles", and issueId is what lets a hit be linked back to its issue.
        foreach (['id', 'rowId', 'localId', 'provider', 'dataset', 'folioCode', 'coreCode', 'dtoType', ...$extraKeys] as $field) {
            $properties[$field] = ['type' => 'keyword'];
        }
        $properties['date'] = ['type' => 'keyword'];

        $this->request('PUT', $base.'/'.$index, $headers, [
            'mappings' => [
                'dynamic_templates' => [[
                    'strings_as_keywords' => [
                        'match_mapping_type' => 'string',
                        'mapping' => ['type' => 'keyword', 'ignore_above' => 1024],
                    ],
                ]],
                'properties' => $properties,
            ],
        ]);
    }

    /** @param list<string> $buffer NDJSON lines, action and document alternating */
    private function flush(SymfonyStyle $io, string $base, array $headers, array $buffer): int
    {
        $body = implode("\n", $buffer)."\n";
        $response = $this->request('POST', $base.'/_bulk', $headers + ['Content-Type' => 'application/x-ndjson'], raw: $body, decode: true);
        $indexed = 0;
        // _bulk answers 200 even when individual documents were rejected, so the per-item errors
        // are the only place a mapping conflict shows up. Silence here would mean a build that
        // reports success and indexed nothing.
        foreach ($response['items'] ?? [] as $item) {
            $outcome = $item['index'] ?? $item['create'] ?? [];
            if (isset($outcome['error'])) {
                $io->writeln(sprintf('  <comment>%s: %s</comment>', $outcome['_id'] ?? '?',
                    $outcome['error']['reason'] ?? 'rejected'));
                continue;
            }
            ++$indexed;
        }

        return $indexed;
    }

    /**
     * The base URL and headers for a DSN, in the form search-bundle's adapter accepts:
     * `elasticsearch://host:port`, `elasticsearch+https://…`, with `?api_key=` and `?ca=`.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function endpoint(string $dsn): array
    {
        $parts = parse_url($dsn);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new \InvalidArgumentException(sprintf('Invalid Elasticsearch DSN "%s".', $dsn));
        }
        $scheme = ($parts['scheme'] ?? '') === 'elasticsearch+https' ? 'https' : 'http';
        $base = sprintf('%s://%s:%d', $scheme, $parts['host'], $parts['port'] ?? 9200);
        parse_str($parts['query'] ?? '', $query);
        $headers = ['Accept' => 'application/json'];
        if (is_string($query['api_key'] ?? null) && $query['api_key'] !== '') {
            $headers['Authorization'] = 'ApiKey '.$query['api_key'];
        }
        $this->caBundle = is_string($query['ca'] ?? null) && $query['ca'] !== '' ? $query['ca'] : null;

        return [$base, $headers];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $json
     * @param list<int> $expected HTTP codes that are not failures; empty means 2xx only
     * @return array<string, mixed>|int decoded body, or the status code when $decode is false
     */
    private function request(string $method, string $url, array $headers, ?array $json = null, array $expected = [], bool $decode = false, ?string $raw = null): array|int
    {
        $options = ['headers' => $headers, 'timeout' => 120];
        if ($this->caBundle !== null) {
            $options['cafile'] = $this->caBundle;
        }
        if ($json !== null) {
            $options['json'] = $json;
        }
        if ($raw !== null) {
            $options['body'] = $raw;
        }
        $response = $this->http->request($method, $url, $options);
        $status = $response->getStatusCode();
        if ($expected !== [] && in_array($status, $expected, true)) {
            return $decode ? $response->toArray(false) : $status;
        }
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Elasticsearch %s %s → %d: %s', $method, $url, $status,
                substr($response->getContent(false), 0, 400)));
        }

        return $decode ? $response->toArray(false) : $status;
    }
}
