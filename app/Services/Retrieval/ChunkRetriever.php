<?php

namespace App\Services\Retrieval;

/**
 * Hybrid retrieval over the shelf of bartending manuals.
 *
 * Every query body lives in HybridRetriever and exists exactly once; this class
 * is the corpus it reads. The public API is unchanged from when the body lived
 * here -- retrieve(), dense(), lexical(), embed(), ::DENSE and ::LEXICAL all
 * resolve through inheritance -- so the thirteen tests that pin this behaviour
 * are the safety net on the generalization rather than casualties of it.
 */
class ChunkRetriever extends HybridRetriever
{
    public function __construct(ReciprocalRankFusion $fusion, Reranker $reranker)
    {
        parent::__construct(CorpusProfile::books(), $fusion, $reranker);
    }
}
