<?php

namespace App\Services\Books;

/**
 * A run of pages whose printed folios advance in step with the physical pages.
 *
 * A book is not one series. When It's Cocktail Time in Cuba runs at an offset of
 * -17 up to physical page 29 and -18 from page 31 onwards, because an unnumbered
 * plate is bound in between. Treating that book as a single series would shift
 * every citation after the plate by one page.
 */
class LabelSeries
{
    public function __construct(
        public string $script,
        public int $offset,
        public int $pageFrom,
        public int $pageTo,
        public int $observations,
    ) {}

    public function covers(int $pageNumber): bool
    {
        return $pageNumber >= $this->pageFrom && $pageNumber <= $this->pageTo;
    }

    /**
     * How far the given page sits outside this series, in pages.
     */
    public function distanceTo(int $pageNumber): int
    {
        return match (true) {
            $pageNumber < $this->pageFrom => $this->pageFrom - $pageNumber,
            $pageNumber > $this->pageTo => $pageNumber - $this->pageTo,
            default => 0,
        };
    }

    /**
     * The label this series implies for a page, or null if it would be absurd.
     */
    public function labelFor(int $pageNumber): ?string
    {
        $number = $pageNumber + $this->offset;

        if ($number < 1) {
            return null;
        }

        return $this->script === 'roman'
            ? RomanNumeral::fromInteger($number)
            : (string) $number;
    }
}
