<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Publisher;

/** One confirmed place a dataset's folio is rendered (GET /api/datasets/{key}/publications). */
final readonly class Publication
{
    public function __construct(
        public string $publisher,
        public string $environment,
        public string $artifactType,
        public string $artifactCode,
        public string $url,
        public string $revision,
        /** False when the confirmed revision is no longer the artifact's current one. */
        public bool $current,
        public ?string $label = null,
        public ?string $occurredAt = null,
        public ?string $confirmedAt = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            publisher: $data['publisher'],
            environment: $data['environment'],
            artifactType: $data['artifactType'],
            artifactCode: $data['artifactCode'] ?? 'default',
            url: $data['url'],
            revision: $data['revision'],
            current: (bool) ($data['current'] ?? false),
            label: $data['label'] ?? null,
            occurredAt: $data['occurredAt'] ?? null,
            confirmedAt: $data['confirmedAt'] ?? null,
        );
    }

    /** What a link should say: the publisher's label, else its code. */
    public function name(): string
    {
        return $this->label ?? $this->publisher;
    }
}
