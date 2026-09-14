<?php

namespace App\Services\Retrieval;

use App\Models\BookChunk;

/**
 * A chunk with the retrieval metadata that found it.
 *
 * The metadata lives here rather than on the model, and that separation is
 * load-bearing. BookChunk::toArray() *is* the prompt payload -- eight keys,
 * asserted by count -- so a score written with setAttribute(), an $appends
 * entry, or a distance aliased into the select would all leak fusion
 * bookkeeping into the language model's context, where it reads as content.
 *
 * Scores are for humans reading `eddie:ask --sources` and for the reranker.
 * They never reach the prompt.
 */
readonly class RetrievedChunk
{
    /**
     * @param  array<string, int>  $ranks  the 1-based rank each channel gave this chunk
     */
    public function __construct(
        public BookChunk $chunk,
        public float $score,
        public array $ranks = [],
        public ?float $rerankScore = null,
    ) {}

    public static function fromFused(BookChunk $chunk, FusedChunk $fused): self
    {
        return new self($chunk, $fused->score, $fused->ranks);
    }

    /**
     * The same chunk, carrying a cross-encoder's opinion of it.
     */
    public function withRerankScore(float $score): self
    {
        return new self($this->chunk, $this->score, $this->ranks, $score);
    }

    public function rankIn(string $channel): ?int
    {
        return $this->ranks[$channel] ?? null;
    }

    /**
     * The string a reranker should score.
     *
     * Deliberately the same provenance-prefixed string the vector was built
     * from, so the cross-encoder and the embedder are looking at one passage
     * rather than two different renderings of it.
     */
    public function rerankText(): string
    {
        return $this->chunk->embeddingText();
    }

    /**
     * Exactly what the language model is handed.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->chunk->toArray();
    }
}
