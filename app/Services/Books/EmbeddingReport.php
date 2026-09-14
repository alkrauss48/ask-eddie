<?php

namespace App\Services\Books;

/**
 * What one book's embedding pass did, in the shape of ChunkingReport.
 *
 * Kept separate from the command for the same reason: the numbers are
 * assertions the run is judged on, not decoration for a table.
 */
class EmbeddingReport
{
    public function __construct(
        public int $indexableCount = 0,
        public int $pendingCount = 0,
        public int $embeddedCount = 0,
        public int $batchCount = 0,
        public int $tokens = 0,
        public float $seconds = 0.0,
    ) {}

    /**
     * Chunks embedded per second, which is the number to extrapolate from.
     *
     * The corpus is 24,926 indexable chunks and the initial bulk embed runs on
     * CPU, so this is what tells you whether a full run is an hour or a day
     * before you commit to finding out.
     */
    public function rate(): float
    {
        return $this->seconds <= 0.0 ? 0.0 : $this->embeddedCount / $this->seconds;
    }

    /**
     * Whether every chunk that was pending got a vector.
     */
    public function isComplete(): bool
    {
        return $this->embeddedCount === $this->pendingCount;
    }
}
