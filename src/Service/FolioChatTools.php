<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Service;

use Survos\FolioBundle\Model\FolioChatHit;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;

/**
 * Agent tools for the folio chat. They let the model actively explore the current collection —
 * run its own searches and drill into a specific item — instead of being limited to the seed rows
 * passed in the prompt. The folio they operate on comes from {@see FolioChatContextHolder}, which
 * {@see FolioChatService} sets for the duration of the agent call.
 */
final class FolioChatTools
{
    /** Keep tool payloads small so multi-call loops stay within the context budget. */
    private const DESCRIPTION_CAP = 400;

    public function __construct(
        private readonly FolioChatContextHolder $holder,
        private readonly FolioRetriever $retriever,
    ) {}

    /**
     * Full-text search the current collection for items matching a query and return the closest
     * matches (id, title, short description). Call this to find items relevant to the question, to
     * compare groups, or to look beyond the items already shown.
     *
     * @param string $query Natural-language search, e.g. "schools and graduating classes" or "maps of Boston"
     * @return list<array{localId: string, title: string, description: string}>
     */
    public function searchCollection(
        string $query,
        #[Schema(description: 'Maximum items to return (1-20).', minimum: 1, maximum: 20)]
        int $limit = 8,
    ): array {
        $ctx = $this->holder->get();
        if ($ctx === null || trim($query) === '') {
            return [];
        }

        return array_map(
            fn (FolioChatHit $hit): array => $this->summarize($hit),
            $this->retriever->retrieve($ctx, $query, max(1, min(20, $limit))),
        );
    }

    /**
     * Fetch the full details of one item by its localId — its title, full description, and any extra
     * catalog fields — to ground a specific, detailed statement about that item.
     *
     * @param string $localId The exact localId of the item, as returned by search_collection.
     * @return array{found: bool, localId?: string, title?: string, description?: string, fields?: array<string, mixed>}
     */
    public function getObject(string $localId): array
    {
        $ctx = $this->holder->get();
        if ($ctx === null || trim($localId) === '') {
            return ['found' => false];
        }

        $hit = $this->retriever->byLocalId($ctx, trim($localId));
        if (!$hit instanceof FolioChatHit) {
            return ['found' => false];
        }

        return [
            'found' => true,
            'localId' => $hit->localId,
            'title' => $hit->label ?? '',
            'description' => $hit->description(900) ?? '',
            // The mapped DTO fields (date, creators, place, …) — already the meaningful, denormalized view.
            'fields' => $hit->dtoData,
        ];
    }

    /**
     * @return array{localId: string, title: string, description: string}
     */
    private function summarize(FolioChatHit $hit): array
    {
        return [
            'localId' => $hit->localId,
            'title' => $hit->label ?? '',
            'description' => $hit->denseSummary() ?? $hit->description(self::DESCRIPTION_CAP) ?? '',
        ];
    }
}
