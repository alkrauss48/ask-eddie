<?php

namespace App\Services\Retrieval;

/**
 * Hybrid retrieval over the house's own menus.
 *
 * The same shape as ChunkRetriever and, deliberately, the same body: one embed,
 * one dense select, one lexical select, one hydrate, fused with RRF. What
 * differs is entirely in the profile -- a different table, a different
 * configuration file, and no `set local hnsw.ef_search` because house_chunks
 * carries no approximate index. That makes house retrieval three queries where
 * books is five: no ef_search statement, and no book to eager load.
 *
 * The knobs differ for a measured reason rather than a stylistic one. 60
 * candidates per channel is 0.24% of the 24,926 book chunks and 29% of the 207
 * house chunks; at that share nearly everything lands in both lists and RRF
 * stops discriminating between them.
 *
 * Reranking is bound contextually in AppServiceProvider rather than globally,
 * because the two corpora have their own rerank.enabled flags and an Apple
 * Silicon dev machine turns the books' off.
 */
class HouseRetriever extends HybridRetriever
{
    public function __construct(ReciprocalRankFusion $fusion, Reranker $reranker)
    {
        parent::__construct(CorpusProfile::house(), $fusion, $reranker);
    }
}
