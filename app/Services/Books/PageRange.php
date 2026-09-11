<?php

namespace App\Services\Books;

/**
 * The pages a chunk came from, in both numbering systems the corpus offers.
 */
class PageRange
{
    public function __construct(
        public int $pageFrom,
        public int $pageTo,
        public ?string $printedFrom = null,
        public ?string $printedTo = null,
        public bool $printedEstimated = false,
    ) {}

    /**
     * The physical span, which is always known.
     */
    public function label(): string
    {
        return $this->pageFrom === $this->pageTo
            ? "PDF p. {$this->pageFrom}"
            : "PDF pp. {$this->pageFrom}–{$this->pageTo}";
    }
}
