<?php

namespace App\Services\Books;

use App\Enums\ChunkStrategy;

class ChunkingReport
{
    /**
     * @param  array<string, int>  $kindCounts
     */
    public function __construct(
        public ChunkStrategy $strategy,
        public int $pageCount = 0,
        public int $sectionCount = 0,
        public int $chunkCount = 0,
        public int $indexableCount = 0,
        public int $medianChars = 0,
        public int $maxTokens = 0,
        public int $hardCuts = 0,
        public int $estimatedLabels = 0,
        public int $unlabelled = 0,
        public int $headingCount = 0,
        public array $kindCounts = [],
        public int $coverageChars = 0,
        public int $streamChars = 0,
    ) {}

    /**
     * The share of the book's assembled text that reached a chunk.
     *
     * This is the phase's central promise made countable. Anything below 100%
     * means text was lost between the page rows and the chunks, which is the
     * one outcome this pipeline treats as a failure rather than a warning.
     */
    public function coverage(): float
    {
        return $this->streamChars === 0 ? 1.0 : $this->coverageChars / $this->streamChars;
    }

    public function isComplete(): bool
    {
        return $this->coverageChars === $this->streamChars;
    }
}
