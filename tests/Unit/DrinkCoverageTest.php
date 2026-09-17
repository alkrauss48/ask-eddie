<?php

use App\Services\Retrieval\DrinkCoverage;

/**
 * The first real run over the corpus defeated the original coverage line. Every
 * one of the 102 books yields at least one drink name -- the tavern histories
 * included -- so "counted 102 of 102" reported full coverage while 44 narrative
 * books supplied a fourteenth of the tally between them. Counting books is not
 * the fact Eddie needs; knowing where the count came from is.
 */
it('names the smallest set of books carrying most of the tally', function (): void {
    // Four books supplying 90, 5, 4 and 1: the first alone clears nine tenths.
    expect(DrinkCoverage::carrying([5, 90, 1, 4]))->toBe(1);

    // Spread evenly, it takes nine of ten.
    expect(DrinkCoverage::carrying(array_fill(0, 10, 10)))->toBe(9);
});

it('reports no carrying books for an untallied shelf', function (): void {
    expect(DrinkCoverage::carrying([]))->toBe(0)
        ->and(DrinkCoverage::carrying([0, 0]))->toBe(0);
});

it('says plainly that an even-looking count is not evenly spread', function (): void {
    $sentence = (new DrinkCoverage(
        booksCounted: 102,
        booksTotal: 102,
        drinkCount: 5_000,
        booksCarrying: 56,
    ))->sentence();

    expect($sentence)->toContain('all 102 books')
        ->and($sentence)->toContain('not spread evenly')
        ->and($sentence)->toContain('56 of them');
});

it('still names the books it could not count when there are any', function (): void {
    $sentence = (new DrinkCoverage(
        booksCounted: 31,
        booksTotal: 45,
        drinkCount: 2_000,
        booksCarrying: 28,
    ))->sentence();

    expect($sentence)->toContain('31 of the 45 books')
        ->and($sentence)->toContain('the other 14');
});

it('drops the concentration clause when every counted book carries its share', function (): void {
    $sentence = (new DrinkCoverage(
        booksCounted: 10,
        booksTotal: 10,
        drinkCount: 100,
        booksCarrying: 10,
    ))->sentence();

    expect($sentence)->not->toContain('not spread evenly');
});
