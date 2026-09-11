<?php

namespace App\Services\Books;

/**
 * A page number as printed in the book, and whether it was read or inferred.
 */
class PrintedLabel
{
    public function __construct(
        public string $value,
        public bool $estimated = false,
    ) {}

    public static function observed(string $value): self
    {
        return new self($value, false);
    }

    public static function inferred(string $value, bool $estimated = true): self
    {
        return new self($value, $estimated);
    }
}
