<?php

use App\Models\HouseCocktail;
use App\Services\Retrieval\CocktailSummary;

/**
 * The payload discipline, at the value object rather than at the tool.
 *
 * HouseCocktail::toArray() never reaches a prompt, which is the safer
 * construction DrinkSummary uses and BookChunk could not: nothing in
 * laravel/ai serializes this model out of the application's reach, so the
 * payload is a value object and a column added to house_cocktails cannot leak
 * into an answer by being forgotten in a $hidden list.
 *
 * Asserted by count rather than by subset, the same as
 * BookChunkCitationTest and DrinkSummaryTest. Do not weaken it.
 */
function summarised(string $slug): CocktailSummary
{
    importHouse();

    $cocktail = HouseCocktail::query()
        ->with(['cocktailIngredients.ingredient', 'tags', 'collections'])
        ->firstWhere('slug', $slug);

    return CocktailSummary::fromCocktail($cocktail);
}

it('hands the model eight keys and no bookkeeping', function (): void {
    $payload = summarised('midnight-rambler')->payload();

    expect($payload)->toHaveCount(8)
        ->and(array_keys($payload))->toEqualCanonicalizing([
            'name', 'description', 'build', 'served', 'tags', 'on', 'notes', 'url',
        ]);
});

/**
 * "source" is the whole exported site object -- image URLs, thumbnails, the
 * nested ingredient bodies -- and it is on the model because a renderer change
 * should be replayable without a re-export. It has no business in a prompt.
 */
it('keeps the raw exported record and the hashes out of the payload', function (): void {
    $payload = summarised('midnight-rambler')->payload();

    foreach (['id', 'slug', 'source', 'content_hash', 'image_url', 'house_bartender_id', 'variations'] as $leak) {
        expect($payload)->not->toHaveKey($leak);
    }
});

/**
 * The build is the reason Sasha may state a recipe at all: it is the house's
 * own line order, free text and measured pours alike, rather than what a model
 * remembers a drink being.
 */
it('renders the build in the order it is poured, free text and all', function (): void {
    expect(summarised('midnight-rambler')->build)->toBe([
        '2oz Rye Whiskey',
        '.5oz Blackberry Syrup',
        'Garnish: Lemon twist',
    ]);

    // "London Dry Gin" rather than "Tanqueray": HouseIngredient::displayName()
    // prints the group, which is the generic class the site groups a build by,
    // so a guest is told what the drink is made of rather than which bottle the
    // house happens to have open.
    expect(summarised('gin-basil-smash')->build)->toBe([
        '2oz London Dry Gin',
        '8 basil leaves',
    ]);
});

/**
 * Three nullable columns joined into the sentence a bartender would say. "None"
 * ice is dropped rather than rendered, because "None ice" is a phrase a model
 * will repeat to a guest.
 */
it('says how a drink is made and what it arrives in as one line', function (): void {
    expect(summarised('midnight-rambler')->served)->toBe('Stirred, in a Nick & Nora Glass')
        ->and(summarised('hot-buttered-rum')->served)->toBe('Built in Glass, in a Mug, Hot ice, serves 4');
});

it('carries the menus and flights a drink is on', function (): void {
    $summary = summarised('midnight-rambler');

    expect($summary->collections)->toEqualCanonicalizing(['Spring Menu', 'The Ramble'])
        // A drink can sit in a menu section *and* in that menu's featured list,
        // and both rows are true -- but the guest hears the menu named once.
        ->and($summary->collections)->toHaveCount(2);
});

it('carries the facet labels a guest was matched on', function (): void {
    expect(summarised('midnight-rambler')->tags)->toBe(['Whiskey', 'Fruity', 'Stirred']);
});
