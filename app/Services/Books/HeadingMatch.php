<?php

namespace App\Services\Books;

/**
 * A line that opens a recipe or a section.
 */
class HeadingMatch
{
    public function __construct(
        public string $text,
        public string $family,
        public ?string $number = null,
        public bool $sectionLike = false,
    ) {}
}
