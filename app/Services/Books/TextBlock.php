<?php

namespace App\Services\Books;

/**
 * The smallest piece of a book that chunking will not cut through.
 */
class TextBlock
{
    public function __construct(
        public string $text,
        public int $start,
        public int $end,
        public ?HeadingMatch $heading = null,
        public int $lineCount = 1,
        public bool $hardCut = false,
    ) {}

    public function length(): int
    {
        return $this->end - $this->start;
    }

    /**
     * Whether this block opens a recipe, as opposed to a division of the book.
     */
    public function opensRecipe(): bool
    {
        return $this->heading !== null && ! $this->heading->sectionLike;
    }

    public function opensSection(): bool
    {
        return $this->heading !== null && $this->heading->sectionLike;
    }
}
