<?php

namespace App\Services\Retrieval;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;
use Throwable;

/**
 * Scores the fused candidates with a cross-encoder and reorders them.
 *
 * Unlike the embedder, which sees a passage and a query separately and hopes
 * their vectors land near each other, a cross-encoder reads both at once. That
 * is worth a round trip when the fused order is close but not right -- which
 * over a corpus of near-identical gin recipes it often is.
 */
class AiReranker implements Reranker
{
    public function __construct(private readonly Reranker $fallback = new NullReranker) {}

    /**
     * @param  Collection<int, RetrievedChunk>  $candidates
     * @return Collection<int, RetrievedChunk>
     */
    public function rerank(string $query, Collection $candidates, int $limit): Collection
    {
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        // Only the head of the fused list is worth a cross-encoder's time, and
        // this is the first lever to pull if the stage is too slow -- lower it
        // before turning reranking off.
        $candidates = $candidates->take((int) config('books.retrieval.rerank.candidates'))->values();

        try {
            $response = Reranking::of($candidates
                // The provenance-prefixed string, so the cross-encoder scores
                // the same rendering of the passage the vector was built from.
                ->map(fn (RetrievedChunk $chunk): string => $chunk->rerankText())
                ->all())
                ->limit($limit)
                ->rerank(
                    $query,
                    (string) config('books.retrieval.rerank.provider'),
                    (string) config('books.retrieval.rerank.model'),
                );
        } catch (Throwable $exception) {
            // Fail closed. A reranking outage degrades to fused order, which is
            // most of the quality anyway; it must never surface as an exception
            // half way through answering a question.
            Log::warning('Reranking failed; falling back to the fused order.', [
                'exception' => $exception->getMessage(),
            ]);

            return $this->fallback->rerank($query, $candidates, $limit);
        }

        return (new Collection($response->results))
            ->map(fn (RankedDocument $ranked): ?RetrievedChunk => $candidates
                ->get($ranked->index)
                ?->withRerankScore($ranked->score))
            ->filter()
            ->take($limit)
            ->values();
    }
}
