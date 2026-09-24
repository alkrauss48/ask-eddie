<?php

use App\Models\HouseCocktail;
use App\Models\HouseIngredient;
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

it('hands the model nine keys and no bookkeeping', function (): void {
    $payload = summarised('midnight-rambler')->payload();

    expect($payload)->toHaveCount(9)
        ->and(array_keys($payload))->toEqualCanonicalizing([
            'name', 'description', 'build', 'bottles', 'served', 'tags', 'on', 'notes', 'url',
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

    // "Tanqueray" rather than "London Dry Gin": the build names the bottle,
    // and the style it belongs to travels in "bottles".
    expect(summarised('gin-basil-smash')->build)->toBe([
        '2oz Tanqueray',
        '8 basil leaves',
    ]);
});

it('groups the bottles in a build under the style each belongs to', function (): void {
    expect(summarised('gin-basil-smash')->bottles)->toBe(['London Dry Gin' => ['Tanqueray']])
        // Rye Whiskey's group only repeats its title, and "Rye Whiskey: Rye
        // Whiskey" tells a guest nothing.
        ->and(summarised('midnight-rambler')->bottles)->toBe([]);
});

/**
 * The defect this exists for: a drink pouring Coruba and Appleton used to read
 * "1oz Jamaican Rum / 1oz Jamaican Rum", and Sasha described two different
 * bottles as the same rum twice.
 */
it('tells two bottles of one style apart and still says they are one style', function (): void {
    importHouse();

    $cocktail = HouseCocktail::firstWhere('slug', 'hot-buttered-rum');
    $coruba = HouseIngredient::create([
        'slug' => 'coruba',
        'title' => 'Coruba',
        'group' => 'Jamaican Rum',
        'url' => '/ingredients',
    ]);
    $cocktail->cocktailIngredients()->create([
        'house_ingredient_id' => $coruba->id,
        'position' => $cocktail->cocktailIngredients()->max('position') + 1,
        'amount' => '.5oz',
    ]);

    $summary = CocktailSummary::fromCocktail(
        $cocktail->fresh(['cocktailIngredients.ingredient', 'tags', 'collections']),
    );

    expect($summary->build)->toBe(['1.5oz Smith and Cross', '6oz boiling water', '.5oz Coruba'])
        ->and($summary->bottles)->toBe(['Jamaican Rum' => ['Smith and Cross', 'Coruba']]);
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
