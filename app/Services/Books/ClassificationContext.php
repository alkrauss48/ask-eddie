<?php

namespace App\Services\Books;

class ClassificationContext
{
    public function __construct(
        public string $language = 'eng',
        public int $pageFrom = 1,
        public int $pageTo = 1,
        public ?int $firstBodyPage = null,
        public ?int $lastBodyPage = null,
        public bool $headingPresent = false,
        public int $recipeHeadings = 0,
        public int $lineCount = 1,
    ) {}

    /**
     * Whether English keyword lists may be applied to this book.
     *
     * Five of the 28 books are French, Spanish or Italian. Running English
     * advertisement and copyright keywords over them would classify real text on
     * a coincidence, so those books are judged on structure alone.
     */
    public function isEnglish(): bool
    {
        return str_starts_with($this->language, 'eng');
    }

    public function isBeforeBody(): bool
    {
        return $this->firstBodyPage !== null && $this->pageTo < $this->firstBodyPage;
    }

    public function isAfterBody(): bool
    {
        return $this->lastBodyPage !== null && $this->pageFrom > $this->lastBodyPage;
    }
}
