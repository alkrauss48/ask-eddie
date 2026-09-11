<?php

use App\Services\Books\PageLabelIndex;

/**
 * Old Waldorf Bar Days: 227 folios read, 220 of them agreeing on an offset of
 * -12, plus a title-page year read as "1931" and a stray "229" on page 13.
 */
it('interpolates a missing folio from a dense series without flagging it', function (): void {
    $index = PageLabelIndex::forBook(pagesWithLabels([
        17 => '5', 18 => '6', 19 => '7', 21 => '9', 22 => '10',
    ], 30));

    $label = $index->labelFor(20);

    expect($label)->not->toBeNull()
        ->and($label->value)->toBe('8')
        // Corroborated on both sides, so this is derived rather than guessed.
        ->and($label->estimated)->toBeFalse();
});

it('flags a folio inferred from only one side of a series', function (): void {
    $index = PageLabelIndex::forBook(pagesWithLabels([
        17 => '5', 18 => '6', 19 => '7',
    ], 30));

    $label = $index->labelFor(21);

    expect($label->value)->toBe('9')
        ->and($label->estimated)->toBeTrue();
});

/**
 * When It's Cocktail Time in Cuba runs at -17 through page 29 and -18 from page
 * 31, because an unnumbered plate is bound in between. Page 30 sits on the
 * plate, and the numbering it belongs to is the one that has not changed yet.
 */
it('keeps two numbering series apart across an inserted plate', function (): void {
    $index = PageLabelIndex::forBook(pagesWithLabels([
        26 => '9', 27 => '10', 29 => '12',
        31 => '13', 32 => '14', 33 => '15',
    ], 40));

    expect($index->series())->toHaveCount(2)
        ->and($index->labelFor(27)->value)->toBe('10')
        ->and($index->labelFor(33)->value)->toBe('15');

    $plate = $index->labelFor(30);

    expect($plate->value)->toBe('13')
        ->and($plate->estimated)->toBeTrue();
});

/**
 * Cafe Royal offers 13 folios across 265 pages and no two of them agree: "1937"
 * from the title page, a stray "C", a "0". A guessed page number is a fabricated
 * citation, so the honest answer is none at all.
 */
it('reports no printed page for a book with no coherent numbering', function (): void {
    $index = PageLabelIndex::forBook(pagesWithLabels(fixtureLabels('cafe-royal-cocktail-book-1937'), 265));

    expect($index->series())->toBe([])
        ->and($index->labelFor(40))->toBeNull()
        ->and($index->labelFor(120))->toBeNull();
});

it('reports no printed page from two unrelated folios', function (): void {
    $index = PageLabelIndex::forBook(pagesWithLabels(fixtureLabels('famous-orleans-drinks-and-how-to-mix-em-1938'), 98));

    expect($index->labelFor(50))->toBeNull();
});

it('recovers a dense series from a real book', function (): void {
    $index = PageLabelIndex::forBook(pagesWithLabels(fixtureLabels('old-waldorf-bar-days-1931'), 264));

    $series = $index->series();

    expect($series)->toHaveCount(1)
        ->and($series[0]->offset)->toBe(-12)
        ->and($index->labelFor(120)->value)->toBe('108')
        ->and($index->labelFor(120)->estimated)->toBeFalse();
});

/**
 * The real piecewise case. When It's Cocktail Time in Cuba carries 285 folios
 * across 305 pages, but they drift through seven offsets as unnumbered plates
 * are bound in, so only 29% of them agree on any single one. A book-wide offset
 * would misprint most of this book's citations.
 */
it('recovers a drifting real book as several series', function (): void {
    $index = PageLabelIndex::forBook(pagesWithLabels(fixtureLabels('when-its-cocktail-time-in-cuba-1928'), 305));

    $series = $index->series();

    expect(count($series))->toBeGreaterThan(1);

    // Every series is arabic, contiguous and in page order.
    $previous = null;

    foreach ($series as $candidate) {
        expect($candidate->script)->toBe('arabic')
            ->and($candidate->pageFrom)->toBeLessThanOrEqual($candidate->pageTo);

        if ($previous !== null) {
            expect($candidate->pageFrom)->toBeGreaterThan($previous->pageTo);
        }

        $previous = $candidate;
    }

    // An observed folio still reads back as itself.
    expect($index->labelFor(27)->value)->toBe('10')
        ->and($index->labelFor(27)->estimated)->toBeFalse();
});

/**
 * Front matter is folioed in roman and the body restarts at 1, so the two never
 * inform each other.
 */
it('never crosses a roman series with an arabic one', function (): void {
    $index = PageLabelIndex::forBook(pagesWithLabels([
        3 => 'i', 4 => 'ii', 5 => 'iii',
        9 => '1', 10 => '2', 11 => '3',
    ], 20));

    expect($index->series())->toHaveCount(2)
        ->and($index->labelFor(6)->value)->toBe('iv')
        ->and($index->labelFor(12)->value)->toBe('4');
});

it('will not extrapolate far past the end of a series', function (): void {
    $index = PageLabelIndex::forBook(pagesWithLabels([
        10 => '1', 11 => '2', 12 => '3',
    ], 200));

    expect($index->labelFor(15))->not->toBeNull()
        ->and($index->labelFor(150))->toBeNull();
});

/**
 * A folio the stream builder recovers from a bracketed page number, such as
 * Old Waldorf's "[ 108 |", is worth as much as one the page normalizer found.
 */
it('accepts a folio discovered while assembling the text', function (): void {
    $index = PageLabelIndex::forBook(pagesWithLabels([], 30));

    expect($index->labelFor(20))->toBeNull();

    $index->observe(18, '6');
    $index->observe(19, '7');

    expect($index->labelFor(20)->value)->toBe('8');
});
