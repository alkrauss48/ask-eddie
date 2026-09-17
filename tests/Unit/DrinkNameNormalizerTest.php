<?php

use App\Services\Books\DrinkClusterer;
use App\Services\Books\DrinkNameNormalizer;

function normalizer(): DrinkNameNormalizer
{
    return app(DrinkNameNormalizer::class);
}

function clusterer(): DrinkClusterer
{
    return app(DrinkClusterer::class);
}

it('folds case, punctuation and spacing to one key', function (): void {
    $keys = collect(['BLUE LADY', 'Blue Lady.', 'blue  lady', 'Blue-Lady'])
        ->map(fn (string $raw): string => normalizer()->key($raw))
        ->unique();

    expect($keys)->toHaveCount(1)
        ->and($keys->first())->toBe('bluelady');
});

/**
 * The corpus is multilingual and its drink names are where the accents live.
 * Str::ascii() is used rather than iconv//TRANSLIT because the latter answers
 * differently depending on the current locale, which would make the key a
 * property of the machine rather than of the string.
 */
it('folds diacritics the same way on any machine', function (): void {
    expect(normalizer()->key('Curaçao'))->toBe('curacao')
        ->and(normalizer()->key('Crème de Menthe'))->toBe('cremedementhe')
        ->and(normalizer()->key('CURACAO'))->toBe('curacao');
});

/**
 * matchNumbered() returns its heading with the trailing period attached while
 * the caps families strip theirs, so without trimEdges() in the fold the same
 * drink would count twice depending on how its book set it.
 */
it('folds a numbered heading and a shouted one to the same key', function (): void {
    expect(normalizer()->key('Gin Sangaree.'))->toBe(normalizer()->key('GIN SANGAREE'));
});

it('strips a leading article and a possessive', function (): void {
    expect(normalizer()->key('The Manhattan'))->toBe('manhattan')
        ->and(normalizer()->key("Bishop's Cocktail"))->toBe(normalizer()->key('Bishops Cocktail'));
});

it('title-cases a shouted name and leaves a set one alone', function (): void {
    expect(normalizer()->display('BLUE LADY'))->toBe('Blue Lady')
        ->and(normalizer()->display('Gin Sangaree.'))->toBe('Gin Sangaree')
        ->and(normalizer()->display('McDonough Punch'))->toBe('McDonough Punch');
});

it('recognises a division of a book as a stop heading', function (): void {
    expect(normalizer()->isStopHeading(normalizer()->key('PUNCHES.')))->toBeTrue()
        ->and(normalizer()->isStopHeading(normalizer()->key('INDEX')))->toBeTrue()
        ->and(normalizer()->isStopHeading(normalizer()->key('BLUE LADY')))->toBeFalse();
});

it('returns the same key for the same input every time', function (): void {
    $first = normalizer()->key('Café Royal Fizz');

    expect(normalizer()->key('Café Royal Fizz'))->toBe($first);
});

it('merges an ocr variant only when fuzzy matching is on', function (): void {
    config()->set('books.drinks.fuzzy.enabled', false);

    expect(clusterer()->resolve('blueladv', ['bluelady' => true]))->toBe('blueladv');

    config()->set('books.drinks.fuzzy.enabled', true);

    expect(clusterer()->resolve('blueladv', ['bluelady' => true]))->toBe('bluelady');
});

it('keeps two genuinely different short names apart', function (): void {
    config()->set('books.drinks.fuzzy.enabled', true);

    // Two edits apart: a substitution and an insertion. Both keys clear
    // the length floor, so it is the budget alone that keeps them apart.
    expect(clusterer()->resolve('ginfix', ['ginfizz' => true]))->toBe('ginfix');
});

/**
 * Documented here rather than in someone's head: one edit on a six-character
 * key catches "BLUE LADV" and it also catches this, and there is no threshold
 * that separates the two. It is why fuzzy matching defaults off, why every
 * merge leaves a receipt in drinks.aliases, and why books:drinks --merges is
 * meant to be read before the flag is ever turned on.
 *
 * If this test ever fails, the clusterer got better and the comment above is
 * out of date -- do not delete the case, prove the improvement with it.
 */
it('merges brandy sour into brandy soup, which is wrong and known', function (): void {
    config()->set('books.drinks.fuzzy.enabled', true);

    expect(clusterer()->resolve('brandysour', ['brandysoup' => true]))->toBe('brandysoup');
});

it('lets configuration force a merge the clusterer would not make', function (): void {
    config()->set('books.drinks.fuzzy.enabled', false);
    config()->set('books.drinks.aliases', ['bluelady' => ['bhielady']]);

    expect(clusterer()->resolve('bhielady', []))->toBe('bluelady');
});

it('lets configuration forbid a merge the clusterer would make', function (): void {
    config()->set('books.drinks.fuzzy.enabled', true);
    config()->set('books.drinks.splits', [['brandysour', 'brandysoup']]);

    expect(clusterer()->resolve('brandysour', ['brandysoup' => true]))->toBe('brandysour');
});
