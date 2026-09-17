<?php

namespace App\Services\Retrieval;

/**
 * How much of the shelf a tally actually covers.
 *
 * This travels with every survey because it is the difference between a true
 * sentence and a false one, and the first real run over the corpus showed that
 * counting books is not enough to make it true. Every one of the 102 books
 * yields at least one drink name -- even the tavern histories -- so a plain
 * "counted N of M" reports full coverage while half the shelf contributes
 * almost nothing. The Art of Drinking (1890) supplies exactly one name from 45
 * chunks and would have been counted as covered.
 *
 * So coverage reports concentration as well: the smallest number of books that
 * together supply nine parts in ten of the count. That needs no threshold to
 * tune, and it is the fact Eddie actually needs -- "most of my books" is a
 * claim about where the tally came from, not about how many books were opened.
 */
readonly class DrinkCoverage
{
    public function __construct(
        public int $booksCounted,
        public int $booksTotal,
        public int $drinkCount,
        public int $booksCarrying = 0,
    ) {}

    public function isEmpty(): bool
    {
        return $this->drinkCount === 0;
    }

    /**
     * The smallest set of books supplying the given share of all mentions.
     *
     * @param  list<int>  $mentionsPerBook
     */
    public static function carrying(array $mentionsPerBook, float $share = 0.9): int
    {
        $total = array_sum($mentionsPerBook);

        if ($total === 0) {
            return 0;
        }

        rsort($mentionsPerBook);

        $running = 0;

        foreach ($mentionsPerBook as $index => $count) {
            $running += $count;

            if ($running >= $share * $total) {
                return $index + 1;
            }
        }

        return count($mentionsPerBook);
    }

    /**
     * The sentence the tool puts above its rows.
     */
    public function sentence(): string
    {
        $uncounted = max(0, $this->booksTotal - $this->booksCounted);

        $opening = $uncounted === 0
            ? "Tallied across all {$this->booksTotal} books on the shelf."
            : "Tallied across {$this->booksCounted} of the {$this->booksTotal} books on the shelf; "
                ."the other {$uncounted} print no drink headings to count.";

        // Said plainly, because "all 102 books" on its own invites a claim about
        // the shelf that only the recipe books can support.
        if ($this->booksCarrying > 0 && $this->booksCarrying < $this->booksCounted) {
            $opening .= ' It is not spread evenly: nine parts in ten of the count come from '
                ."{$this->booksCarrying} of them, the recipe books. The rest are narrative and "
                .'name a drink only in passing.';
        }

        return $opening;
    }
}
