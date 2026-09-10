<?php

namespace App\Services\Books;

/**
 * A page's text-quality score, or the reason it could not be scored.
 */
class QualityScore
{
    /**
     * @param  array<string, mixed>  $breakdown
     */
    public function __construct(
        public ?float $score,
        public array $breakdown = [],
        public ?string $reason = null,
    ) {}

    public static function unscorable(string $reason): self
    {
        return new self(score: null, breakdown: [], reason: $reason);
    }

    /**
     * Whether the page cleared the configured bar for usable text.
     *
     * An unscorable page is never treated as passing: too little text to judge
     * is a reason to look closer, not a reason to trust it.
     */
    public function passes(?float $threshold = null): bool
    {
        if ($this->score === null) {
            return false;
        }

        return $this->score >= ($threshold ?? (float) config('books.quality.min_score'));
    }
}
