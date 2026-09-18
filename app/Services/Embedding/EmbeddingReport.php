<?php

namespace App\Services\Embedding;

/**
 * What one embedding pass did, in the shape of ChunkingReport.
 *
 * Kept separate from the command for the same reason: the numbers are
 * assertions the run is judged on, not decoration for a table.
 *
 * Shared by both corpora. The pass it describes is a book for books:embed and
 * the whole house for house:embed, which is the only difference -- the
 * questions asked of it (did everything pending get a vector, and how fast) are
 * the same either way.
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
     * The book corpus is 24,926 indexable chunks and its initial bulk embed
     * runs on CPU, so this is what tells you whether a full run is an hour or a
     * day before you commit to finding out. The house's 207 do not need the
     * warning, but they are embedded by the same emulated container and it
     * costs nothing to report.
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
