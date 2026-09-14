<?php

namespace App\Services\Retrieval;

use Illuminate\Support\Collection;

/**
 * The final ordering step, behind a seam.
 *
 * A seam rather than a direct call because "no reranking" is a legitimate mode
 * and must be indistinguishable from the retriever's point of view. Hybrid
 * search plus RRF is the bulk of the quality gain here; over 25,000 short
 * recipe passages a cross-encoder's marginal value is smaller than it would be
 * over long documents, and it costs real latency. Measure before assuming it
 * earns its place.
 */
interface Reranker
{
    /**
     * Order the candidates by relevance to the query and take the best.
     *
     * @param  Collection<int, RetrievedChunk>  $candidates  in fused order
     * @return Collection<int, RetrievedChunk>
     */
    public function rerank(string $query, Collection $candidates, int $limit): Collection;
}
