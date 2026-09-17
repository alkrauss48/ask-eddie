<?php

namespace App\Services\Retrieval;

use App\Models\Drink;

/**
 * One drink's counts, over whichever books the question asked about.
 *
 * It exists because the same four numbers have two meanings. With no year
 * bounds they are the materialized columns on `drinks`, recomputed wholesale by
 * DrinkExtractor. With bounds they are counted afresh over the mentions inside
 * the window, and they are usually much smaller -- "Mint Julep" is in 27 books
 * on this shelf and in 3 of the 6 the shelf holds from the 1860s.
 *
 * Before this existed, a year filter narrowed which drinks came back and then
 * ranked them on the corpus-wide column, so a survey of the 1860s returned the
 * same eight drinks in the same order as a survey of everything. The question
 * looked answered and was not.
 */
readonly class DrinkTally
{
    public function __construct(
        public int $bookCount,
        public int $mentionCount,
        public ?int $firstYear,
        public ?int $lastYear,
    ) {}

    public static function fromDrink(Drink $drink): self
    {
        return new self(
            $drink->book_count,
            $drink->mention_count,
            $drink->first_year,
            $drink->last_year,
        );
    }

    /**
     * The span of years, as a citation renders it.
     *
     * Collapsed when both ends agree, the same way BookChunk::pages collapses a
     * single-page range, and empty when no book carrying it records a year.
     */
    public function yearRange(): string
    {
        if ($this->firstYear === null && $this->lastYear === null) {
            return '';
        }

        $from = $this->firstYear ?? $this->lastYear;
        $to = $this->lastYear ?? $this->firstYear;

        return $from === $to ? (string) $from : "{$from}–{$to}";
    }
}
