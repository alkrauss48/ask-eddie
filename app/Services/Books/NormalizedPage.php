<?php

namespace App\Services\Books;

/**
 * The result of normalizing a single page of extracted text.
 */
class NormalizedPage
{
    public function __construct(
        public string $text,
        public ?string $printedPageLabel = null,
    ) {}

    public function characterCount(): int
    {
        return mb_strlen($this->text);
    }

    public function wordCount(): int
    {
        return count(preg_split('/\s+/u', trim($this->text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }
}
