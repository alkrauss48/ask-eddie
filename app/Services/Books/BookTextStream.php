<?php

namespace App\Services\Books;

/**
 * A whole book as one string, with an index back to the pages it came from.
 *
 * Chunking works on this rather than page by page. In this corpus 1,445 pages
 * (29%) end mid-sentence and 127 end mid-word, so cutting each page
 * independently would sever a third of the corpus's sentences and split
 * recipes that run over a page turn.
 *
 * The largest book here assembles to roughly 275 KB, so it is held in memory
 * whole. That is deliberate and worth leaving alone: streaming it would buy
 * nothing and would cost the offset arithmetic that makes citations checkable.
 */
class BookTextStream
{
    /**
     * @param  list<PageSpan>  $pages  in page order
     */
    public function __construct(
        public string $text,
        public array $pages,
        public string $checksum,
    ) {}

    public function length(): int
    {
        return strlen($this->text);
    }

    /**
     * Offsets are byte offsets, not character offsets.
     *
     * PHP's preg_* functions report byte offsets when capturing positions, and
     * the whole boundary search is built on them, so everything here counts
     * bytes for consistency. Every cut lands on a line, sentence or word
     * boundary, so a slice is always valid UTF-8 even where the text is not
     * ASCII -- and this corpus is full of "Curaçao".
     */
    public function slice(int $start, int $end): string
    {
        return substr($this->text, $start, max(0, $end - $start));
    }

    /**
     * The pages a half-open range of the stream touches.
     *
     * @return list<PageSpan>
     */
    public function pagesCovering(int $start, int $end): array
    {
        $covering = array_values(array_filter(
            $this->pages,
            fn (PageSpan $span): bool => ! $span->isEmpty() && $span->intersects($start, $end),
        ));

        if ($covering !== []) {
            return $covering;
        }

        // A range that lands entirely inside a join separator belongs to the
        // page that precedes it.
        $fallback = null;

        foreach ($this->pages as $span) {
            if ($span->start <= $start) {
                $fallback = $span;
            }
        }

        return $fallback === null ? [] : [$fallback];
    }

    /**
     * The page range for a chunk, in both numbering systems.
     *
     * Blank leaves inside the range are counted, so a passage spanning pages 10
     * and 12 across a blank page 11 reports 10 to 12 rather than pretending the
     * blank is not there.
     */
    public function pageRangeFor(int $start, int $end): ?PageRange
    {
        $covering = $this->pagesCovering($start, $end);

        if ($covering === []) {
            return null;
        }

        $first = $covering[0];
        $last = $covering[count($covering) - 1];

        return new PageRange(
            $first->pageNumber,
            $last->pageNumber,
            $first->printedLabel,
            $last->printedLabel,
            $first->printedLabelEstimated || $last->printedLabelEstimated,
        );
    }
}
