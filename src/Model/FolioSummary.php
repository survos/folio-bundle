<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Model;

final readonly class FolioSummary
{
    /**
     * @param list<array{code:string,label:?string,rowCount:int}> $cores
     * @param array<string,int> $coreCounts
     * @param array<string,int> $dtoCounts
     */
    public function __construct(
        public ?int $rowCount,
        public array $cores = [],
        public array $coreCounts = [],
        public array $dtoCounts = [],
    ) {
    }
}
