<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Publisher;

/** Harvest's view of one publisher/environment (reset, GET and selection responses). */
final readonly class PublisherRegistration
{
    public function __construct(
        public string $publisher,
        public string $environment,
        public int $generation,
        public PublisherSelection $selection,
        public ?string $label = null,
        public ?string $baseUrl = null,
        public ?string $registeredAt = null,
        public ?string $resetAt = null,
        public int $published = 0,
        public int $withdrawn = 0,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            publisher: $data['publisher'],
            environment: $data['environment'],
            generation: (int) $data['generation'],
            selection: PublisherSelection::fromArray($data['selection'] ?? []),
            label: $data['label'] ?? null,
            baseUrl: $data['baseUrl'] ?? null,
            registeredAt: $data['registeredAt'] ?? null,
            resetAt: $data['resetAt'] ?? null,
            published: (int) ($data['counts']['published'] ?? 0),
            withdrawn: (int) ($data['counts']['withdrawn'] ?? 0),
        );
    }
}
