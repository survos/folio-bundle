<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Set;

use Survos\DatasetBundle\Entity\Artifact;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;

/**
 * Resolves an app's folio sets (survos_folio.folio_sets) against the dataset registry. A set is a
 * code, a label and criteria — never a list of folios — so membership is derived and can always be
 * rebuilt: a dataset joins a set by gaining a tag in its metadata, not by being added anywhere.
 * See docs/folio-sets.md. folio:sets:sync records the result; members() reads it back.
 *
 * Criteria, all optional and ANDed together:
 *   tags        any of these tags            provider     any of these providers
 *   tagsAll     all of these tags            contentType  any of these content types
 *   minRows     at least this many rows (default 1)
 *
 * Only datasets with a built folio are candidates: a set is of folios, not datasets.
 */
final class FolioSetResolver
{
    /** @var array<string, list<string>> folio file → content types, read once per process */
    private array $contentTypes = [];

    /** @param array<string, array{label: ?string, core: string, criteria: array<string, mixed>}> $sets */
    public function __construct(
        private readonly array $sets,
        private readonly string $membershipDir,
        private readonly ?DatasetInfoRepository $datasets = null,
    ) {}

    /** @return array<string, array{label: ?string, core: string, criteria: array<string, mixed>}> */
    public function sets(): array
    {
        return $this->sets;
    }

    public function has(string $code): bool
    {
        return isset($this->sets[$code]);
    }

    /**
     * Evaluate a set's criteria against the registry now.
     *
     * @return list<array{datasetKey: string, label: ?string, provider: string, tags: list<string>, contentTypes: list<string>, rowCount: ?int, folio: ?string}>
     */
    public function resolve(string $code): array
    {
        $set = $this->sets[$code] ?? throw new \InvalidArgumentException(sprintf('No folio set "%s". Defined: %s.', $code, implode(', ', array_keys($this->sets)) ?: 'none'));
        if ($this->datasets === null) {
            throw new \RuntimeException('The dataset registry is not available, so folio sets cannot be resolved. Is survos/dataset-bundle configured?');
        }
        $members = [];
        foreach ($this->datasets->findAll() as $info) {
            $candidate = $this->candidate($info, $set['criteria']);
            if ($candidate !== null && self::matches($set['criteria'], $candidate)) {
                $members[] = $candidate;
            }
        }
        usort($members, static fn (array $a, array $b): int => strcmp($a['datasetKey'], $b['datasetKey']));

        return $members;
    }

    /**
     * The recorded membership from the last folio:sets:sync, or a live resolution if there is none.
     *
     * @return list<array<string, mixed>>
     */
    public function members(string $code): array
    {
        $recorded = $this->recorded($code);

        return $recorded !== null ? $recorded['members'] : $this->resolve($code);
    }

    /** @return array{code: string, label: ?string, criteria: array<string, mixed>, resolvedAt: string, members: list<array<string, mixed>>}|null */
    public function recorded(string $code): ?array
    {
        $file = $this->membershipFile($code);
        if (!is_file($file)) {
            return null;
        }

        return json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    }

    public function membershipFile(string $code): string
    {
        return rtrim($this->membershipDir, '/').'/'.$code.'.json';
    }

    /** Whether a candidate meets every criterion. Public and static so it is testable on plain arrays. */
    public static function matches(array $criteria, array $candidate): bool
    {
        $norm = static fn (array $values): array => array_map(static fn ($v): string => strtolower(trim((string) $v)), $values);
        $tags = $candidate['tags'];
        if (($criteria['tags'] ?? []) !== [] && array_intersect($norm($criteria['tags']), $tags) === []) {
            return false;
        }
        if (($criteria['tagsAll'] ?? []) !== [] && array_diff($norm($criteria['tagsAll']), $tags) !== []) {
            return false;
        }
        if (($criteria['provider'] ?? []) !== [] && !in_array($candidate['provider'], $norm($criteria['provider']), true)) {
            return false;
        }
        if (($criteria['contentType'] ?? []) !== [] && array_intersect($norm($criteria['contentType']), $candidate['contentTypes']) === []) {
            return false;
        }

        return ($candidate['rowCount'] ?? 0) >= (int) ($criteria['minRows'] ?? 1);
    }

    private function candidate(DatasetInfo $info, array $criteria): ?array
    {
        $artifact = $info->artifact(Artifact::TYPE_FOLIO);
        if ($artifact === null) {
            return null;
        }
        $folio = $artifact->uri !== null && is_file($artifact->uri) ? $artifact->uri : null;

        return [
            'datasetKey' => $info->datasetKey,
            'label' => $info->label,
            'provider' => strtolower($info->provider()),
            'tags' => $info->getTags(),
            // Only worked out when a criterion needs it: it may mean opening the folio file.
            'contentTypes' => ($criteria['contentType'] ?? []) !== [] ? $this->contentTypes($artifact, $folio) : [],
            'rowCount' => $artifact->rowCount,
            'folio' => $folio,
        ];
    }

    /**
     * A folio's content types: the artifact's recorded contentType if the registry has one,
     * otherwise the DTO types in the folio's own schema_table.
     *
     * @return list<string>
     */
    private function contentTypes(Artifact $artifact, ?string $folio): array
    {
        if (is_string($artifact->metadata['contentType'] ?? null)) {
            return [strtolower($artifact->metadata['contentType'])];
        }
        if ($folio === null) {
            return [];
        }
        if (!isset($this->contentTypes[$folio])) {
            $pdo = new \Pdo\Sqlite('sqlite:'.$folio, options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY,
            ]);
            // schema_table records each DTO table's type; a multi-core folio has several
            // (cron-america: doc → newspaper, article → document).
            $this->contentTypes[$folio] = array_values(array_map('strtolower', $pdo->query(
                'SELECT DISTINCT dto_type FROM schema_table WHERE dto_type IS NOT NULL'
            )->fetchAll(\PDO::FETCH_COLUMN)));
        }

        return $this->contentTypes[$folio];
    }
}
