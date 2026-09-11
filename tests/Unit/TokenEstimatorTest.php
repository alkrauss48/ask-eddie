<?php

use App\Services\Books\TokenEstimator;

beforeEach(function (): void {
    $this->estimator = new TokenEstimator;
});

it('counts nothing for empty text', function (string $text): void {
    expect($this->estimator->estimate($text))->toBe(0);
})->with(['', '   ', "\n\n"]);

/**
 * The failure modes are not symmetric. Over-estimating costs a slightly smaller
 * chunk; under-estimating means the embedding model truncates the tail of a
 * recipe and nothing anywhere reports it.
 */
it('over-estimates rather than under-estimates against plain english', function (): void {
    $text = str_repeat('The bartender mixed a drink for the gentleman at the bar. ', 20);

    // English prose runs about four characters per token; anything at or above
    // that ratio is the safe side of the error.
    expect($this->estimator->estimate($text))
        ->toBeGreaterThan((int) (strlen($text) / 4.2));
});

/**
 * Fractions and accented names fragment into more tokens per character than
 * prose does, which is why the estimate carries a penalty for them.
 */
it('estimates fraction-heavy recipe text higher per character than prose', function (): void {
    $recipe = "1/2 Booth's Dry Gin.\n1/4 Blue Curaçao.\n1/4 Orange Bitters.\n3 dashes Bénédictine.";
    $prose = 'The bartender mixed a drink for the gentleman who was waiting at the bar.';

    $recipeRate = $this->estimator->estimate($recipe) / strlen($recipe);
    $proseRate = $this->estimator->estimate($prose) / strlen($prose);

    expect($recipeRate)->toBeGreaterThan($proseRate);
});

/**
 * The reason the ceiling exists: whatever embedding model Phase 3 chooses, a
 * chunk must fit its window without being truncated.
 */
it('keeps a full-size chunk inside a 512-token window', function (): void {
    $ceiling = (int) config('books.chunking.max_chars');
    $text = str_repeat('a', $ceiling);

    expect($this->estimator->estimate($text) + (int) config('books.chunking.prefix_reserve_tokens'))
        ->toBeLessThanOrEqual(512);
});

/**
 * Overlap is stored as part of a chunk's text, so it spends the same budget.
 * Packing content to the full ceiling left no room for it and the overlap was
 * silently dropped.
 */
it('reserves room for overlap in the content ceilings', function (): void {
    expect($this->estimator->contentCharacterCeiling())
        ->toBe($this->estimator->characterCeiling() - (int) config('books.chunking.overlap_chars'))
        ->and($this->estimator->contentTokenCeiling())
        ->toBeLessThan($this->estimator->tokenCeiling());
});

it('reserves room for the provenance prefix in the token ceiling', function (): void {
    expect($this->estimator->tokenCeiling())
        ->toBe((int) config('books.chunking.max_tokens') - (int) config('books.chunking.prefix_reserve_tokens'));
});
