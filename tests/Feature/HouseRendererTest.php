<?php

use App\Enums\HouseSourceType;
use App\Models\HouseBartender;
use App\Models\HouseChunk;
use App\Models\HouseCocktail;
use App\Models\HouseCollection;
use App\Models\HouseRecipe;
use App\Services\House\HouseRenderer;

/**
 * What a drink sounds like when it is written down.
 *
 * This is the class with the most product in it and the least machinery: there
 * is no packing, no boundary detection and no offset arithmetic to test,
 * because a record is a chunk. What there is to get wrong is whether the
 * passage says the things a guest's sentence has to match -- how it is built,
 * what is in it, and which menu it is on.
 */
beforeEach(function (): void {
    importHouse();
});

function renderedChunk(HouseSourceType $type, string $slug): HouseChunk
{
    return HouseChunk::query()
        ->where('source_type', $type)
        ->where('source_slug', $slug)
        ->sole();
}

it('renders a cocktail as its name, its build and its facets', function (): void {
    $text = renderedChunk(HouseSourceType::Cocktail, 'midnight-rambler')->text;

    expect($text)->toStartWith("Midnight Rambler\nOur own house original")
        ->and($text)->toContain('Rye, blackberry, lemon, a long twist.')
        ->and($text)->toContain('2oz Rye Whiskey')
        ->and($text)->toContain('Garnish: Lemon twist')
        ->and($text)->toContain('Credited to Antoine Peychaud.')
        ->and($text)->toContain('Express the twist over the surface');
});

/**
 * The build is a sentence rather than a list of key-value pairs because this
 * string is embedded, and "shaken", "over crushed ice" and "in a tiki mug" are
 * things a guest says. "ice: Crushed" is not.
 */
/**
 * The line names the bottle, so the style has to be said separately or
 * "London Dry Gin" drops out of the text a guest's sentence is matched against.
 */
it('names the bottle in the build and the style it is grouped under', function (): void {
    $text = renderedChunk(HouseSourceType::Cocktail, 'gin-basil-smash')->text;

    expect($text)->toContain("2oz Tanqueray\n")
        ->and($text)->toContain('London Dry Gin: Tanqueray.')
        ->and($text)->not->toContain('2oz London Dry Gin');
});

it('says how a drink is built in words a guest would use', function (): void {
    expect(renderedChunk(HouseSourceType::Cocktail, 'gin-basil-smash')->text)
        ->toContain('Shaken, served in a Double Rocks Glass, over small cubes.');
});

it('does not describe a hot drink as being served over ice', function (): void {
    $text = renderedChunk(HouseSourceType::Cocktail, 'hot-buttered-rum')->text;

    expect($text)->toContain('Built in Glass, served in a Mug, served hot.')
        ->and($text)->not->toContain('over hot ice')
        ->and($text)->toContain('Serves 4.');
});

/**
 * "None" is how the site says a drink is served in nothing in particular, and
 * saying so out loud reads as a mistake rather than as an absence.
 */
it('says nothing at all about a glass or ice the site left as None', function (): void {
    $text = renderedChunk(HouseSourceType::Cocktail, 'midnight-rambler')->text;

    expect($text)->toContain('Stirred, served in a Nick & Nora Glass, with no ice.')
        ->and($text)->not->toContain('None');
});

it('names the menus and flights a drink is on', function (): void {
    expect(renderedChunk(HouseSourceType::Cocktail, 'midnight-rambler')->text)
        ->toContain('On the Spring Menu, The Ramble flight.');
});

it('renders a variation without losing what it changes', function (): void {
    expect(renderedChunk(HouseSourceType::Cocktail, 'midnight-rambler')->text)
        ->toContain('Long Rambler: Top with 2oz soda and serve in a highball.');
});

/**
 * The reason the 131 ingredients can stay out of the corpus: what the catalog
 * knows about a bottle travels with every drink that pours it.
 */
it('carries an ingredient\'s catalog vocabulary onto the drinks that use it', function (): void {
    $keywords = renderedChunk(HouseSourceType::Cocktail, 'hot-buttered-rum')->keywords;

    expect($keywords)->toContain('Smith and Cross')
        ->and($keywords)->toContain('Jamaican Rum')
        ->and($keywords)->toContain('Base Spirits')
        ->and($keywords)->toContain('Rum');
});

/**
 * "None" and "Default" are the site's own placeholders. Indexed, they would
 * make every drink carrying one match a guest who happened to type the word.
 */
it('keeps the site\'s placeholder labels out of the keyword index', function (): void {
    foreach (HouseChunk::all() as $chunk) {
        expect($chunk->keywords)->not->toContain('None')
            ->and($chunk->keywords)->not->toContain('Default');
    }
});

it('renders a recipe as its lines and its method', function (): void {
    $chunk = renderedChunk(HouseSourceType::Recipe, 'blackberry-syrup');

    expect($chunk->text)->toContain('12oz blackberries')
        ->and($chunk->text)->toContain('Simmer until the berries collapse')
        ->and($chunk->text)->toContain('Keeps two weeks refrigerated.')
        ->and($chunk->subtitle)->toBe('Syrups')
        ->and($chunk->url)->toBe('https://thekrausshaus.com/recipes/blackberry-syrup');
});

it('renders a bartender with their years and what the house pours of theirs', function (): void {
    $chunk = renderedChunk(HouseSourceType::Bartender, 'antoine-peychaud');

    expect($chunk->text)->toContain("Antoine Peychaud\n1803–1883")
        ->and($chunk->text)->toContain('New Orleans apothecary')
        ->and($chunk->text)->toContain('On the menus: Midnight Rambler.');
});

it('leaves the years out for a bartender who has none recorded', function (): void {
    $chunk = renderedChunk(HouseSourceType::Bartender, 'sasha-house');

    expect($chunk->subtitle)->toBeNull()
        ->and($chunk->text)->toStartWith("Sasha House\n\nWorks the stick");
});

it('renders a menu as its sections and its featured drinks', function (): void {
    $text = renderedChunk(HouseSourceType::Menu, 'spring')->text;

    expect($text)->toContain('Bright: Gin Basil Smash.')
        ->and($text)->toContain('Dark: Midnight Rambler.')
        ->and($text)->toContain('Featured: Midnight Rambler.');
});

it('renders a flight in the order it is meant to be drunk', function (): void {
    $text = renderedChunk(HouseSourceType::Path, 'the-ramble')->text;

    expect($text)->toContain('Opens herbal and green')
        ->and($text)->toContain('Gin Basil Smash, Midnight Rambler.');
});

/**
 * The ceiling is an assertion, not a packing hint -- the same standing
 * max_chars has in ChunkPacker. Everything in the corpus fits today, so this
 * throw looks like dead code; it is not. A flight of twenty cocktails added
 * next year would otherwise render long, be embedded truncated, and retrieve on
 * text nobody chose.
 */
it('throws rather than letting a long record be silently truncated', function (): void {
    config()->set('house.rendering.max_chars', 80);

    expect(fn () => app(HouseRenderer::class)->renderCocktail(
        HouseCocktail::with(['bartender', 'tags', 'collections', 'cocktailIngredients.ingredient'])
            ->firstWhere('slug', 'midnight-rambler')
    ))->toThrow(RuntimeException::class, 'over the 80-character ceiling');
});

it('keeps every rendered record inside the ceiling', function (): void {
    $ceiling = (int) config('house.rendering.max_chars');

    expect(HouseChunk::query()->where('char_count', '>', $ceiling)->count())->toBe(0);
});

/**
 * Deterministic in, deterministic out: rendering the same record twice must
 * produce the same bytes, or nothing downstream can use a hash to decide
 * whether anything moved.
 */
it('renders the same record to the same bytes twice', function (): void {
    $renderer = app(HouseRenderer::class);

    $cocktail = HouseCocktail::with(['bartender', 'tags', 'collections', 'cocktailIngredients.ingredient'])
        ->firstWhere('slug', 'midnight-rambler');

    expect($renderer->renderCocktail($cocktail)->contentHash())
        ->toBe($renderer->renderCocktail($cocktail->fresh())->contentHash());
});

it('measures the tokens of the embedded string rather than of the body', function (): void {
    $chunk = renderedChunk(HouseSourceType::Cocktail, 'midnight-rambler');

    expect($chunk->char_count)->toBe(mb_strlen($chunk->text))
        ->and($chunk->token_estimate)->toBeGreaterThan(0)
        ->and($chunk->word_count)->toBeGreaterThan(0);
});

it('renders every kind of record the corpus holds', function (): void {
    $renderer = app(HouseRenderer::class);

    expect($renderer->renderRecipe(HouseRecipe::first()))->not->toBeNull()
        ->and($renderer->renderBartender(HouseBartender::first()))->not->toBeNull()
        ->and($renderer->renderCollection(HouseCollection::with('cocktails')->first()))->not->toBeNull();
});
