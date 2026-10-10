<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Publisher;

/**
 * One publisher event about one registry variant: "I published revision R at URL U" or "I stopped
 * publishing it". The revision is opaque — compared for equality, never ordered.
 */
final readonly class PublicationReceipt
{
    public const DEFAULT_CODE = 'default';

    public function __construct(
        public string $datasetKey,
        public string $artifactType,
        public string $artifactCode,
        public ReceiptState $state,
        /** The publisher's own time of the local publish/withdrawal; Harvest orders a variant's events by it. */
        public string $occurredAt,
        public ?string $revision = null,
        public ?string $url = null,
    ) {
        if ($state === ReceiptState::Published) {
            if ($revision === null || $revision === '') {
                throw new \InvalidArgumentException(sprintf('A published receipt for %s needs the revision that was published.', $datasetKey));
            }
            if ($url === null || !preg_match('#^https?://[^/]+#i', $url)) {
                throw new \InvalidArgumentException(sprintf('A published receipt for %s needs an absolute http(s) URL.', $datasetKey));
            }
        }
    }

    /** The registry's unique variant key; one pending receipt per variant, the latest wins. */
    public function variant(): string
    {
        return self::variantKey($this->datasetKey, $this->artifactType, $this->artifactCode);
    }

    public static function variantKey(string $datasetKey, string $artifactType, string $artifactCode): string
    {
        return $datasetKey."\t".$artifactType."\t".$artifactCode;
    }

    public function isPublished(): bool
    {
        return $this->state === ReceiptState::Published;
    }

    /** True when both describe the same publication (ignoring when it happened). */
    public function sameAs(self $other): bool
    {
        return $this->variant() === $other->variant() && $this->state === $other->state
            && $this->revision === $other->revision && $this->url === $other->url;
    }

    /** @return array<string, string> the wire shape, which is also the checkpoint shape */
    public function toArray(): array
    {
        return array_filter([
            'datasetKey' => $this->datasetKey,
            'artifactType' => $this->artifactType,
            'artifactCode' => $this->artifactCode,
            'state' => $this->state->value,
            'revision' => $this->revision,
            'url' => $this->url,
            'occurredAt' => $this->occurredAt,
        ], static fn (?string $value): bool => $value !== null);
    }

    /** @param array<string, string> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            datasetKey: $data['datasetKey'],
            artifactType: $data['artifactType'],
            artifactCode: $data['artifactCode'] ?? self::DEFAULT_CODE,
            state: ReceiptState::from($data['state'] ?? ReceiptState::Published->value),
            occurredAt: $data['occurredAt'],
            revision: $data['revision'] ?? null,
            url: $data['url'] ?? null,
        );
    }
}
