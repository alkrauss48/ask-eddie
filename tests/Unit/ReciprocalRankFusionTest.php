<?php

use App\Services\Retrieval\FusedChunk;
use App\Services\Retrieval\ReciprocalRankFusion;

function fuse(array $channels, array $weights = [], int $k = 60): array
{
    return array_map(
        fn (FusedChunk $chunk): int => $chunk->id,
        (new ReciprocalRankFusion)->fuse($channels, $weights, $k),
    );
}

/**
 * The property the whole hybrid design is bought for: agreement between two
 * channels beats a single channel's enthusiasm.
 */
it('ranks a chunk both channels found above one only a single channel found', function (): void {
    $order = fuse([
        'dense' => [10, 20, 30],
        'lexical' => [40, 20, 50],
    ]);

    // 20 is second in both lists; 10 and 40 are each first in one.
    expect($order[0])->toBe(20);
});

it('scores a single-list chunk by its rank alone', function (): void {
    $fused = (new ReciprocalRankFusion)->fuse(['dense' => [7, 8]], k: 60);

    expect($fused[0]->score)->toBe(1 / 61)
        ->and($fused[1]->score)->toBe(1 / 62);
});

it('sums a chunk found by both channels', function (): void {
    $fused = (new ReciprocalRankFusion)->fuse([
        'dense' => [5],
        'lexical' => [5],
    ], k: 60);

    expect($fused)->toHaveCount(1)
        ->and($fused[0]->score)->toBe(2 / 61);
});

/**
 * k is what stops a first place from being worth more than everything else. At
 * k = 0 rank 1 scores 1.0 and rank 2 scores 0.5, so nothing can catch a
 * single-channel winner; at k = 60 the gap is 1/61 against 1/62.
 */
it('damps the top of each list in proportion to k', function (): void {
    $steep = (new ReciprocalRankFusion)->fuse(['dense' => [1, 2]], k: 0);
    $damped = (new ReciprocalRankFusion)->fuse(['dense' => [1, 2]], k: 60);

    expect($steep[0]->score / $steep[1]->score)->toBe(2.0)
        ->and(round($damped[0]->score / $damped[1]->score, 4))->toBe(1.0164);
});

it('applies a per-channel weight', function (): void {
    $even = fuse(['dense' => [1], 'lexical' => [2]]);
    $lexical = fuse(['dense' => [1], 'lexical' => [2]], ['lexical' => 2.0]);

    // With equal weights the tie breaks on id; doubling lexical breaks it on score.
    expect($even[0])->toBe(1)
        ->and($lexical[0])->toBe(2);
});

it('records the rank each channel gave a chunk', function (): void {
    $fused = (new ReciprocalRankFusion)->fuse([
        'dense' => [9, 4],
        'lexical' => [4],
    ]);

    $four = collect($fused)->firstWhere('id', 4);

    expect($four->rankIn('dense'))->toBe(2)
        ->and($four->rankIn('lexical'))->toBe(1);
});

/**
 * A channel that returns nothing must be visible rather than merely suspected,
 * which is what the per-channel ranks are for.
 */
it('reports a null rank for a channel that did not find a chunk', function (): void {
    $fused = (new ReciprocalRankFusion)->fuse([
        'dense' => [3],
        'lexical' => [],
    ]);

    expect($fused[0]->rankIn('lexical'))->toBeNull()
        ->and($fused[0]->rankIn('dense'))->toBe(1);
});

it('breaks ties deterministically by id', function (): void {
    $first = fuse(['dense' => [30, 10, 20]], k: 60);
    $tied = fuse(['dense' => [5], 'lexical' => [3]]);

    expect($first)->toBe([30, 10, 20])
        // Same score, so the lower id wins, both times it is asked.
        ->and($tied)->toBe([3, 5])
        ->and(fuse(['dense' => [5], 'lexical' => [3]]))->toBe([3, 5]);
});

it('fuses nothing into nothing', function (): void {
    expect(fuse(['dense' => [], 'lexical' => []]))->toBe([]);
});
