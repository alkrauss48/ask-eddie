<?php

namespace App\Services\Books;

/**
 * A chunk decided but not yet classified or persisted.
 */
class PendingChunk
{
    /**
     * @param  list<string>  $headings
     * @param  array<string, mixed>  $signals
     */
    public function __construct(
        public string $text,
        public int $start,
        public int $end,
        public int $overlapChars = 0,
        public ?string $heading = null,
        public array $headings = [],
        public int $recipeHeadings = 0,
        public int $lineCount = 1,
        public array $signals = [],
    ) {}

    public function length(): int
    {
        return $this->end - $this->start;
    }

    /**
     * Where this chunk's own content begins, excluding borrowed overlap.
     *
     * The page range is taken from here, so a chunk never cites a page it only
     * borrowed a preamble from.
     */
    public function contentStart(): int
    {
        return $this->start + $this->overlapChars;
    }
}
