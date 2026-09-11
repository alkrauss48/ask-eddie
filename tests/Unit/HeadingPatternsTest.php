<?php

use App\Services\Books\HeadingPatterns;

beforeEach(function (): void {
    $this->patterns = new HeadingPatterns;
});

/**
 * The Python original required that a heading NOT end in a period, which
 * disabled detection across most of this corpus: "GIN SLING." and "PORTER
 * SANGAREE." are the normal form here.
 */
it('detects an all-caps heading that ends in a period', function (string $line): void {
    expect($this->patterns->match($line))->not->toBeNull();
})->with(['PUNCHES.', 'GIN SLING.', 'TODDIES AND SLINGS']);

it('reads a numbered recipe heading', function (): void {
    $match = $this->patterns->match('128. Gin Sangaree.');

    expect($match)->not->toBeNull()
        ->and($match->family)->toBe(HeadingPatterns::NUMBERED)
        ->and($match->text)->toBe('Gin Sangaree.')
        ->and($match->number)->toBe('128')
        ->and($match->sectionLike)->toBeFalse();
});

/**
 * The scanner read 130 as "13u" on page 60 of the 1862 Jerry Thomas. The number
 * is captured for the record but never relied on, because the sequence is not
 * trustworthy.
 */
it('still reads a recipe heading whose number the scanner mangled', function (): void {
    $match = $this->patterns->match('13u. Porter Sangaree.');

    expect($match)->not->toBeNull()
        ->and($match->text)->toBe('Porter Sangaree.')
        ->and($match->number)->toBe('13u');
});

it('treats a shouted numbered line as a division rather than a recipe', function (): void {
    $match = $this->patterns->match('131. TODDIES AND SLINGS');

    expect($match->sectionLike)->toBeTrue();
});

/**
 * An ingredient line opens with a measure, and a required separator after the
 * number is what tells the two apart.
 */
it('does not mistake an ingredient line for a numbered recipe', function (string $line): void {
    $match = $this->patterns->match($line);

    expect($match?->family)->not->toBe(HeadingPatterns::NUMBERED);
})->with(['1 Wine-glass of brandy.', '2 dashes of bitters', '1 table-spoonful of fine white sugar.']);

/**
 * Cafe Royal prints the drink name and its first ingredient on one line,
 * because pdftotext -layout merged the book's two columns.
 */
it('lifts a drink name off the line it shares with an ingredient', function (): void {
    $match = $this->patterns->match('BLUE LADY 1/2 Blue Curasao (Gamier).');

    expect($match)->not->toBeNull()
        ->and($match->family)->toBe(HeadingPatterns::CAPS_PREFIX)
        ->and($match->text)->toBe('BLUE LADY');
});

/**
 * A short title-cased line is not evidence of anything on its own -- OCR wraps
 * prose into them constantly. What makes it a heading is a measure on the next
 * line.
 */
it('reads a title-cased recipe name only when a measure follows it', function (): void {
    expect($this->patterns->match('Whiskey Cocktail.', '1 dash of gum syrup.'))->not->toBeNull()
        ->and($this->patterns->match('Whiskey Cocktail.', 'and so the evening wore on for'))->toBeNull();
});

/**
 * trim() with a character list matches bytes, so a list containing a multibyte
 * dash could shear one byte off an unrelated character. That produced a heading
 * ending in half of the "c" in "Curacao" and an insert Postgres rejected.
 */
it('leaves accented characters intact when trimming a heading', function (): void {
    $match = $this->patterns->match('CURAÇAO PUNCH.');

    expect($match->text)->toBe('CURAÇAO PUNCH')
        ->and(mb_check_encoding($match->text, 'UTF-8'))->toBeTrue();
});

/**
 * A page set entirely in capitals -- page 9 of Old Waldorf Bar Days is a
 * dedication -- would otherwise report fourteen headings, and make a narrative
 * book look densely structured.
 */
it('collapses a run of shouted lines into one heading', function (): void {
    $lines = [
        'CERTAIN GENTLEMEN OF OTHER DAYS,',
        'WHO MADE OF DRINKING',
        'NOT ONE OF ITS EVILS;',
        'WHO ACHIEVED CONTENT',
    ];

    expect($this->patterns->scan($lines))->toHaveCount(1);
});

it('ignores the folio zone when scanning a page', function (): void {
    $lines = ['GIN SLING.', '', 'Some prose about the drink.', '', 'PUNCHES.'];

    $headings = $this->patterns->scan($lines, $this->patterns->folioZone($lines));

    expect($headings)->toHaveCount(0);
});
