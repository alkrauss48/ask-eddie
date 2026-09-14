<?php

namespace App\Services\Retrieval;

use Illuminate\Support\Collection;

/**
 * Trusts the fused order and takes the top n.
 *
 * Not a stub. This is the mode the application runs in when reranking is turned
 * off, and it is also what it degrades to when the reranking service cannot be
 * reached -- a cross-encoder outage must cost some ordering quality, never an
 * exception in the middle of answering a guest.
 */
class NullReranker implements Reranker
{
    /**
     * @param  Collection<int, RetrievedChunk>  $candidates
     * @return Collection<int, RetrievedChunk>
     */
    public function rerank(string $query, Collection $candidates, int $limit): Collection
    {
        return $candidates->take($limit)->values();
    }
}
