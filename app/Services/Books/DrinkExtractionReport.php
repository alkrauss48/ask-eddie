<?php

namespace App\Services\Books;

use App\Enums\ChunkStrategy;

/**
 * What one book's drink pass found, in the shape of ChunkingReport.
 *
 * The numbers are the run's evidence rather than decoration: a book that
 * contributed nothing is the finding, not a blank row.
 */
class DrinkExtractionReport
{
    public function __construct(
        public ChunkStrategy $strategy = ChunkStrategy::Packing,
        public int $chunkCount = 0,
        public int $mentionCount = 0,
        public int $drinkCount = 0,
        public int $stopHeadingCount = 0,
        public int $pageCount = 0,
        /** @var list<string> */
        public array $mergedKeys = [],
    ) {}

    /**
     * Drink names per page, which is what tells a narrative book from a recipe
     * book without reading either.
     */
    public function density(): float
    {
        return $this->pageCount <= 0 ? 0.0 : $this->mentionCount / $this->pageCount;
    }

    public function contributedNothing(): bool
    {
        return $this->mentionCount === 0;
    }
}
