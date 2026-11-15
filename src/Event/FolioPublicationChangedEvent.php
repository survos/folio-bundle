<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Event;

use Survos\FolioBundle\Catalog\FolioCatalogEntry;
use Survos\FolioBundle\Service\DuckDbRenditionReader;

/** Delivered in each reader process before it uses a changed publication, including withdrawals. */
final readonly class FolioPublicationChangedEvent
{
    /** @param list<FolioCatalogEntry> $previous @param list<FolioCatalogEntry> $current */
    public function __construct(public string $datasetKey, public array $previous, public array $current)
    {
    }

    public function affects(string $uri): bool
    {
        foreach ([...$this->previous, ...$this->current] as $entry) {
            if (null === $entry->remoteUri) {
                continue;
            }
            if ($uri === $entry->remoteUri) {
                return true;
            }
            if (preg_match('~\.(?:dfolio|duckdb)$~D', $entry->remoteUri)) {
                $rend = DuckDbRenditionReader::rendUri($entry->remoteUri);
                if ($uri === $rend || $uri === substr($rend, 0, -6).'.dwords') {
                    return true;
                }
            }
        }

        return false;
    }
}
