<?php

namespace App\Services\Books;

/**
 * Where one page's text sits inside its book's assembled stream.
 *
 * A blank leaf contributes a zero-length span rather than no span at all, so
 * that a passage running across it still reports the true page range.
 */
class PageSpan
{
    public function __construct(
        public int $pageNumber,
        public int $start,
        public int $end,
        public ?string $printedLabel = null,
        public bool $printedLabelEstimated = false,
    ) {}

    public function length(): int
    {
        return $this->end - $this->start;
    }

    public function isEmpty(): bool
    {
        return $this->length() === 0;
    }

    /**
     * Whether this page contributed any of the given half-open range.
     */
    public function intersects(int $start, int $end): bool
    {
        return $this->start < $end && $start < $this->end;
    }
}
