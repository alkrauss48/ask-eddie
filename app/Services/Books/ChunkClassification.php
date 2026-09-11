<?php

namespace App\Services\Books;

use App\Enums\ChunkKind;

class ChunkClassification
{
    /**
     * @param  array<string, mixed>  $signals  the measurements behind the verdict
     */
    public function __construct(
        public ChunkKind $kind,
        public array $signals = [],
        public ?float $qualityScore = null,
    ) {}

    public function isIndexable(): bool
    {
        return $this->kind->isIndexable();
    }
}
