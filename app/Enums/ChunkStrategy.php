<?php

namespace App\Enums;

enum ChunkStrategy: string
{
    /**
     * Chunks are anchored to detected headings and a recipe is never split.
     * Chosen for books whose heading structure is dense enough to measure; see
     * SectionDetector::strategyFor().
     */
    case Headings = 'headings';

    /**
     * Paragraph blocks are packed to a size target. Chosen for prose books and
     * for anything whose heading signal is too thin to trust.
     */
    case Packing = 'packing';

    public function label(): string
    {
        return match ($this) {
            self::Headings => 'headings',
            self::Packing => 'packing',
        };
    }
}
