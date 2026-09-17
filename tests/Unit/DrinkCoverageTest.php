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

/**
 * A windowed tally counts a slice, and the slice's size is the frame. Without
 * it "three books print this" is heard as a claim about the whole shelf; the
 * shelf holds six books from the 1860s, so three is half of everything that
 * decade could possibly say.
 */
it('frames a windowed tally by the books the window holds', function (): void {
    $sentence = (new DrinkCoverage(
        booksCounted: 3,
        booksTotal: 6,
        drinkCount: 40,
        booksCarrying: 1,
        window: '1860–1869',
    ))->sentence();

    expect($sentence)->toStartWith('Tallied over 1860–1869 only')
        ->and($sentence)->toContain('3 books')
        ->and($sentence)->toContain('not out of the whole shelf')
        ->and($sentence)->not->toContain('on the shelf.');
});

it('says plainly when the window holds no books at all', function (): void {
    $sentence = (new DrinkCoverage(
        booksCounted: 0,
        booksTotal: 0,
        drinkCount: 40,
        window: '1700–1750',
    ))->sentence();

    expect($sentence)->toContain('no books from');
});

it('agrees in number for a window holding one book', function (): void {
    $sentence = (new DrinkCoverage(
        booksCounted: 1,
        booksTotal: 1,
        drinkCount: 40,
        window: '1862',
    ))->sentence();

    expect($sentence)->toContain('1 book on the shelf')
        ->and($sentence)->not->toContain('1 books');
});

/**
 * first_year is the earliest book on this shelf that prints a drink, not where
 * the drink came from, and nothing in the rows themselves says so.
 */
it('warns that a chronological ranking is about the shelf, not about history', function (): void {
    $sentence = (new DrinkCoverage(
        booksCounted: 102,
        booksTotal: 102,
        drinkCount: 2_382,
        shelfOrdered: true,
    ))->sentence();

    expect($sentence)->toContain('not the same as when it was invented');
});

it('leaves the chronology caveat off every other ranking', function (): void {
    $sentence = (new DrinkCoverage(
        booksCounted: 102,
        booksTotal: 102,
        drinkCount: 2_382,
    ))->sentence();

    expect($sentence)->not->toContain('when it was invented');
});

/**
 * A shelf whose every row the classifier set aside has still been tallied.
 * Collapsing that into "not yet counted" would report an absence from the books
 * when what happened is that everything found was noise.
 */
it('separates a shelf nobody counted from one that counted only noise', function (): void {
    expect((new DrinkCoverage(booksCounted: 0, booksTotal: 5, drinkCount: 0, rowsTallied: 0))->isEmpty())
        ->toBeTrue()
        ->and((new DrinkCoverage(booksCounted: 5, booksTotal: 5, drinkCount: 0, rowsTallied: 900))->isEmpty())
        ->toBeFalse();
});
