<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Publisher;

/** What a publisher subscribes to. Intent only: it never implies a folio is published. */
final readonly class PublisherSelection
{
    /**
     * @param list<string> $tags        for {@see SelectionMode::Tags}
     * @param list<string> $datasetKeys for {@see SelectionMode::Datasets}
     */
    public function __construct(
        public SelectionMode $mode = SelectionMode::All,
        public array $tags = [],
        public array $datasetKeys = [],
    ) {}

    /** @param array{mode?: string, tags?: list<string>, datasetKeys?: list<string>} $data */
    public static function fromArray(array $data): self
    {
        return new self(SelectionMode::from($data['mode'] ?? SelectionMode::All->value), array_values($data['tags'] ?? []), array_values($data['datasetKeys'] ?? []));
    }

    public function toArray(): array
    {
        return ['mode' => $this->mode->value] + match ($this->mode) {
            SelectionMode::Tags => ['tags' => $this->tags],
            SelectionMode::Datasets => ['datasetKeys' => $this->datasetKeys],
            default => [],
        };
    }
}
