<?php

namespace App\Services\Books;

use App\Enums\SectionKind;

class DetectedSection
{
    /**
     * @param  array<string, list<int>>  $headVariants  every spelling of the head that produced
     *                                                  this section, and the pages it appeared on
     */
    public function __construct(
        public ?string $title,
        public SectionKind $kind,
        public int $pageFrom,
        public int $pageTo,
        public array $headVariants = [],
        public ?float $confidence = null,
    ) {}

    public function covers(int $pageNumber): bool
    {
        return $pageNumber >= $this->pageFrom && $pageNumber <= $this->pageTo;
    }
}
