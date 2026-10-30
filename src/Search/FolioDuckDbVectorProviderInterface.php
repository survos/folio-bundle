<?php

declare(strict_types=1);

namespace Survos\FolioBundle\Search;

/** Host-owned embeddings must match the model and dimensions recorded in the snapshot. */
interface FolioDuckDbVectorProviderInterface
{
    /** @return array{vectorColumn:string,vectorDimensions:int,minimumSimilarity:float,vectorCoverage?:array{indexed:int,total:int},queryEmbedder:\Survos\SearchBundle\Adapter\DuckDb\DuckDbQueryEmbedderInterface}|array{} */
    public function parameters(string $folioCode): array;
}
