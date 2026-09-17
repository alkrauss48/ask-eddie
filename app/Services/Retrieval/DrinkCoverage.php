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
 *
 * It carries two further frames, both of which exist because a true number can
 * still be said misleadingly. A windowed survey states the window and how many
 * books the window holds, or "three books print this" reads as a claim about
 * the shelf when the shelf only has six books from that decade. And a survey
 * ordered by first or last printing states that those years are facts about
 * this shelf, not about where a drink came from.
 */
readonly class DrinkCoverage
{
    public function __construct(
        public int $booksCounted,
        public int $booksTotal,
        public int $drinkCount,
        public int $booksCarrying = 0,
        public ?string $window = null,
        public bool $shelfOrdered = false,
        public ?int $rowsTallied = null,
    ) {}

    /**
     * Whether the shelf has been tallied at all.
     *
     * Deliberately not "are there any countable drinks". A corpus whose every
     * row the classifier set aside has still been counted, and saying otherwise
     * would collapse the two facts SurveyTheBooks exists to keep apart -- a
     * tally nobody ran, and a tally that found nothing to report. Falls back to
     * the countable count only when no total was supplied.
     */
    public function isEmpty(): bool
    {
        return ($this->rowsTallied ?? $this->drinkCount) === 0;
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
        return implode(' ', array_filter([
            $this->window === null ? $this->shelfSentence() : $this->windowSentence(),
            $this->concentration(),
            $this->chronologyCaveat(),
        ]));
    }

    private function shelfSentence(): string
    {
        $uncounted = max(0, $this->booksTotal - $this->booksCounted);

        return $uncounted === 0
            ? "Tallied across all {$this->booksTotal} books on the shelf."
            : "Tallied across {$this->booksCounted} of the {$this->booksTotal} books on the shelf; "
                ."the other {$uncounted} print no drink headings to count.";
    }

    /**
     * A windowed tally counts a slice, and the slice's size is the frame.
     *
     * Without it "three books print this" is heard as a claim about the whole
     * shelf. The shelf holds six books from the 1860s, so three is half of what
     * that decade could possibly say -- a different and much stronger fact.
     */
    private function windowSentence(): string
    {
        if ($this->booksCounted === 0) {
            return "Tallied over {$this->window}, a period this shelf holds no books from.";
        }

        return "Tallied over {$this->window} only: {$this->booksCounted} book"
            .($this->booksCounted === 1 ? '' : 's')
            .' on the shelf printed anything in that period, and every count below is '
            .'out of those, not out of the whole shelf.';
    }

    /**
     * Said plainly, because "all 102 books" on its own invites a claim about the
     * shelf that only the recipe books can support.
     */
    private function concentration(): string
    {
        if ($this->booksCarrying <= 0 || $this->booksCarrying >= $this->booksCounted) {
            return '';
        }

        return 'It is not spread evenly: nine parts in ten of the count come from '
            ."{$this->booksCarrying} of them, the recipe books. The rest are narrative and "
            .'name a drink only in passing.';
    }

    /**
     * First and last printing are facts about acquisition, not about history.
     *
     * The shelf is thin before 1880 -- six books from the 1860s, one of which
     * supplies four fifths of the decade's names -- so "the earliest" is
     * reliably the earliest book here and only sometimes the earliest anywhere.
     */
    private function chronologyCaveat(): string
    {
        if (! $this->shelfOrdered) {
            return '';
        }

        return 'These years are when a drink was printed in a book on this shelf, which is '
            .'not the same as when it was invented; say it that way.';
    }
}
