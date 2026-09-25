<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Configuration;

/** Declared search policy; runtime indexing/routing support is deliberately separate. */
final readonly class FolioSearchConfiguration
{
    public const string SQLITE = 'sqlite';
    public const string ELASTICSEARCH = 'elasticsearch';

    public function __construct(
        public string $backend = self::SQLITE,
        public bool $allowFtsSkip = false,
    ) {
        if (!in_array($backend, [self::SQLITE, self::ELASTICSEARCH], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported folio search backend "%s".', $backend));
        }
        if ($allowFtsSkip && $backend !== self::ELASTICSEARCH) {
            throw new \InvalidArgumentException('Skipping FTS5 requires an Elasticsearch search backend.');
        }
    }

    /** @param array<string, mixed> $extras Dataset metadata extras. */
    public static function fromExtras(array $extras): self
    {
        $search = $extras['search'] ?? [];
        if (!is_array($search)
            || array_diff(array_keys($search), ['backend', 'allowFtsSkip']) !== []
            || (array_key_exists('backend', $search) && !is_string($search['backend']))
            || (array_key_exists('allowFtsSkip', $search) && !is_bool($search['allowFtsSkip']))
        ) {
            throw new \InvalidArgumentException('Expected extras.search with backend (string) and allowFtsSkip (boolean).');
        }

        return new self($search['backend'] ?? self::SQLITE, $search['allowFtsSkip'] ?? false);
    }

    /** @return array{backend: string, allowFtsSkip: bool} */
    public function toArray(): array
    {
        return ['backend' => $this->backend, 'allowFtsSkip' => $this->allowFtsSkip];
    }
}
