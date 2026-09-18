<?php

namespace App\Services\Retrieval;

/**
 * One chunk id with its fused score and the rank each channel gave it.
 *
 * The per-channel ranks are kept rather than discarded because they are the
 * only way to see that a channel returned nothing: a fused list where every
 * entry has a dense rank and none has a lexical one looks exactly like a
 * working hybrid search from the outside. `bar:ask --sources` prints them.
 */
readonly class FusedChunk
{
    /**
     * @param  array<string, int>  $ranks  the 1-based rank each channel gave this chunk
     */
    public function __construct(
        public int $id,
        public float $score,
        public array $ranks = [],
    ) {}

    public function rankIn(string $channel): ?int
    {
        return $this->ranks[$channel] ?? null;
    }
}
