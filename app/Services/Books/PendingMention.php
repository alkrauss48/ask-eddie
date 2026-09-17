<?php

namespace App\Services\Books;

/**
 * One printed drink name found in a chunk, before it is folded or persisted.
 */
class PendingMention
{
    public function __construct(
        public string $rawHeading,
        public string $family,
        public ?string $number,
        /** Byte offset of the heading line within its chunk's text. */
        public int $offsetInChunk,
    ) {}
}
