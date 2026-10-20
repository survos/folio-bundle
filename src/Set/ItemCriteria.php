<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Set;

/** Item predicates, independent of the criteria that select whole folios. */
final readonly class ItemCriteria
{
    /** @param list<string> $tags Any exact, case-sensitive item tag; empty means unrestricted. */
    public function __construct(public int $minImages = 0, public array $tags = [])
    {
        if ($minImages < 0) {
            throw new \InvalidArgumentException('minImages must be non-negative.');
        }
        if (!array_is_list($tags) || array_any($tags, static fn (mixed $tag): bool => !is_string($tag) || '' === trim($tag))) {
            throw new \InvalidArgumentException('tags must be a list of non-empty strings.');
        }
    }

    /** @param list<string> $itemTags */
    public function matchesTags(array $itemTags): bool
    {
        return [] === $this->tags || array_any($this->tags, static fn (string $tag): bool => in_array($tag, $itemTags, true));
    }
}
