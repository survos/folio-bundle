<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Catalog;

use Survos\DataContracts\Vocabulary\ItemField;

/** A published immutable snapshot, not a local acquisition or a download request. */
final readonly class RemoteDuckDbSnapshot
{
    public function __construct(public string $locale, public string $uri, public string $revision)
    {
    }

    /** @return array<string, self> Indexed by content language; old catalogs simply have none. */
    public static function fromDataset(array $dataset): array
    {
        $snapshots = [];
        foreach ($dataset[DatasetField::ARTIFACTS] as $artifact) {
            if (DatasetField::DUCKDB_TYPE !== $artifact[ItemField::TYPE]
                || !($artifact[DatasetField::AVAILABLE] ?? false)
                || null === ($artifact[DatasetField::REMOTE_URI] ?? null)) {
                continue;
            }
            $uri = $artifact[DatasetField::REMOTE_URI];
            $parts = parse_url($uri);
            $prefix = '/'.$dataset[DatasetField::DATASET_KEY].'/';
            if (false === $parts || ($parts['scheme'] ?? null) !== 's3'
                || !isset($parts['host'], $parts['path'])
                || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts))
                || in_array('..', explode('/', $parts['path']), true)
                || !preg_match('~'.preg_quote($prefix, '~').'[a-f0-9]{64}\.dfolio$~D', $parts['path'])) {
                throw new \UnexpectedValueException('Invalid published DuckDB snapshot address.');
            }
            $locale = $artifact[DatasetField::LOCALE];
            if (!is_string($locale) || !preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $locale)) {
                throw new \UnexpectedValueException('Published DuckDB snapshot requires a content language.');
            }
            if (isset($snapshots[$locale])) {
                throw new \UnexpectedValueException('Duplicate published DuckDB language: '.$locale);
            }
            $snapshots[$locale] = new self($locale, $uri, $artifact[DatasetField::REVISION]);
        }

        return $snapshots;
    }
}
