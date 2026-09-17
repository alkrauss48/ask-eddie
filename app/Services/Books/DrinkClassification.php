<?php

namespace App\Services\Books;

/**
 * Whether a drink row may be counted, and the measurements behind the verdict.
 *
 * Shaped like ChunkClassification, and for the same reason: the verdict is a
 * column retrieval filters on, and the signals are the receipt that makes a
 * wrong verdict diagnosable by reading the row.
 */
class DrinkClassification
{
    /**
     * @param  array<string, mixed>  $signals
     */
    public function __construct(
        public bool $isCountable,
        public string $reason,
        public array $signals = [],
    ) {}

    /**
     * @param  array<string, mixed>  $signals
     */
    public static function countable(string $reason, array $signals = []): self
    {
        return new self(true, $reason, $signals);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    public static function uncountable(string $reason, array $signals = []): self
    {
        return new self(false, $reason, $signals);
    }
}
