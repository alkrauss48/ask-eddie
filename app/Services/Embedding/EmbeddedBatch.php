<?php

namespace App\Services\Embedding;

/**
 * One batch's vectors, checked and ready to write.
 *
 * Reaching this object at all is the guarantee: BatchEmbedder throws rather
 * than returning a batch whose count or width is wrong, so nothing downstream
 * has to re-check either.
 */
class EmbeddedBatch
{
    /**
     * @param  list<list<float>>  $vectors
     */
    public function __construct(
        public readonly array $vectors,
        public readonly int $tokens,
        public readonly string $model,
        public readonly int $dimensions,
    ) {}

    /**
     * One vector in pgvector's own literal form.
     */
    public function literal(int $index): string
    {
        return '['.implode(',', $this->vectors[$index]).']';
    }

    public function count(): int
    {
        return count($this->vectors);
    }
}
