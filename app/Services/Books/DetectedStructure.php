<?php

namespace App\Services\Books;

use App\Enums\ChunkStrategy;
use App\Enums\SectionKind;

/**
 * What a book's own running heads reveal about how it is organised.
 */
class DetectedStructure
{
    /**
     * @param  list<DetectedSection>  $sections
     * @param  array<int, string>  $headByPage  page number => the exact line to remove
     */
    public function __construct(
        public array $sections,
        public array $headByPage,
        public HeadEdge $edge,
        public ChunkStrategy $strategy,
        public float $headingsPerPage = 0.0,
    ) {}

    /**
     * The section a page belongs to, ignoring the running-head receipts.
     */
    public function sectionFor(int $pageNumber): ?DetectedSection
    {
        $match = null;

        foreach ($this->sections as $section) {
            if ($section->kind === SectionKind::RunningHead) {
                continue;
            }

            // Later sections win, so a chapter beats the whole-book fallback.
            if ($section->covers($pageNumber)) {
                $match = $section;
            }
        }

        return $match;
    }

    /**
     * The head to strip from a page, if that page's head was proven redundant.
     */
    public function headFor(int $pageNumber): ?string
    {
        return $this->headByPage[$pageNumber] ?? null;
    }
}
