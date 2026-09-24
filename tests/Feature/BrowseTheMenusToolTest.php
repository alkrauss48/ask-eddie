<?php

use App\Tools\BrowseTheMenus;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;

function menuTool(): BrowseTheMenus
{
    return app(BrowseTheMenus::class);
}

/**
 * Run the tool against the fixture house and return the parsed rows.
 *
 * @return list<array<string, mixed>>
 */
function browsed(array $arguments = []): array
{
    importHouse();

    $output = menuTool()->handle(new Request($arguments));
    $start = strpos($output, '[');

    expect($start)->not->toBeFalse(
        "The tool returned no rows:\n{$output}",
    );

    return json_decode(substr($output, (int) $start), true, flags: JSON_THROW_ON_ERROR);
}

function browsedText(array $arguments = []): string
{
    importHouse();

    return (string) menuTool()->handle(new Request($arguments));
}

function namesIn(array $rows): array
{
    return array_column($rows, 'name');
}

/**
 * The reason this tool exists at all.
 *
 * "I don't like whiskey" is a negation, and an embedding of "not whiskey" sits
 * among the whiskey drinks -- a search answers it plausibly and wrongly every
 * time, and a model handed a Sazerac will name it. A WHERE clause answers it
 * exactly, and the fixture's three drinks are one gin, one rum and one whiskey
 * so the exclusion is visible rather than statistical.
 */
it('rules out a base spirit entirely', function (): void {
    $names = namesIn(browsed(['without_base_spirit' => ['Whiskey']]));

    expect($names)->toEqualCanonicalizing(['Gin Basil Smash', 'Hot Buttered Rum'])
        ->and($names)->not->toContain('Midnight Rambler');
});

it('rules out a base spirit however the guest capitalised it', function (): void {
    // The model writes "whiskey" as often as "Whiskey", and a facet filter that
    // is silently case-sensitive answers a real question with an empty list.
    expect(namesIn(browsed(['without_base_spirit' => ['whiskey']])))
        ->toEqualCanonicalizing(['Gin Basil Smash', 'Hot Buttered Rum']);
});

it('rules out an ingredient a guest cannot have', function (): void {
    expect(namesIn(browsed(['without_ingredient' => ['Blackberry Syrup']])))
        ->toEqualCanonicalizing(['Gin Basil Smash', 'Hot Buttered Rum']);
});

it('keeps only the drinks built on the spirits asked for', function (): void {
    expect(namesIn(browsed(['base_spirit' => ['Gin', 'Rum']])))
        ->toEqualCanonicalizing(['Gin Basil Smash', 'Hot Buttered Rum']);
});

/**
 * Categories are ANDed and the labels inside one are ORed, because "a gin or
 * vodka drink that is also herbal" is the question a guest asks and "a drink
 * that is both gin and vodka" is not.
 */
it('ands across categories and ors within one', function (): void {
    expect(namesIn(browsed([
        'base_spirit' => ['Gin', 'Whiskey'],
        'flavor' => ['Herbal'],
    ])))->toBe(['Gin Basil Smash']);
});

it('finds a drink by an ingredient, by its catalog name or by the bottle', function (): void {
    expect(namesIn(browsed(['with_ingredient' => ['Jamaican Rum']])))->toBe(['Hot Buttered Rum'])
        ->and(namesIn(browsed(['with_ingredient' => ['Smith and Cross']])))->toBe(['Hot Buttered Rum'])
        ->and(namesIn(browsed(['with_ingredient' => ['smith-and-cross']])))->toBe(['Hot Buttered Rum']);
});

it('narrows to a single menu or flight', function (): void {
    expect(namesIn(browsed(['menu' => 'Spring Menu'])))
        ->toEqualCanonicalizing(['Gin Basil Smash', 'Midnight Rambler'])
        ->and(namesIn(browsed(['menu' => 'the-ramble'])))
        ->toEqualCanonicalizing(['Gin Basil Smash', 'Midnight Rambler']);
});

it('narrows on the single-valued facets', function (): void {
    expect(namesIn(browsed(['technique' => 'Stirred'])))->toBe(['Midnight Rambler'])
        ->and(namesIn(browsed(['temperature' => 'Hot'])))->toBe(['Hot Buttered Rum']);
});

/**
 * Five facets are scalars sitting beside six that are arrays, and a model that
 * has just filled in an array parameter sends the next one the same way. That
 * used to cast an array to a string: "Array to string conversion", promoted to
 * an ErrorException by HandleExceptions, caught by the tool's own catch-all and
 * reported to the guest as an outage the menus were not having. Unpromoted it
 * was worse and quieter -- the value became the literal "Array", matched no
 * facet, and the tool said nothing the house pours fits, which is the false
 * negative the three distinct returns exist to keep apart from a true one.
 */
it('reads a single-valued facet whether the model sends a string or an array', function (): void {
    expect(namesIn(browsed(['technique' => ['Stirred']])))->toBe(namesIn(browsed(['technique' => 'Stirred'])))
        ->and(namesIn(browsed(['temperature' => ['Hot']])))->toBe(['Hot Buttered Rum'])
        ->and(namesIn(browsed(['menu' => ['Spring Menu']])))->toBe(namesIn(browsed(['menu' => 'Spring Menu'])));
});

/**
 * "What's good?" has to be answerable with no arguments at all, the same
 * property DrinkQuery holds.
 */
it('answers with no arguments at all', function (): void {
    expect(namesIn(browsed()))->toHaveCount(3);
});

/**
 * A drink on a menu and in a flight is one the house leans on, which is a
 * better answer than whichever title sorts first -- and the title and id after
 * it mean a repeated question gives a repeated answer.
 */
it('leads with the drinks on the most curated lists', function (): void {
    // Midnight Rambler is on the spring menu twice (a section and the featured
    // list) and in the Ramble; Gin Basil Smash is on each once.
    expect(namesIn(browsed())[0])->toBe('Midnight Rambler');
});

it('returns exactly the nine payload keys and nothing else', function (): void {
    foreach (browsed() as $row) {
        expect(array_keys($row))->toEqualCanonicalizing([
            'name', 'description', 'build', 'bottles', 'served', 'tags', 'on', 'notes', 'url',
        ])->and($row)->toHaveCount(9);
    }
});

it('leaks no id, slug, hash or raw exported record', function (): void {
    $output = browsedText();

    foreach (['"id"', 'slug', 'content_hash', 'image_url', 'thumbnail', 'source'] as $leak) {
        expect(strtolower($output))->not->toContain($leak);
    }
});

it('honours an explicit limit', function (): void {
    expect(browsed(['limit' => 1]))->toHaveCount(1);
});

/**
 * The denominator travels with the answer for the reason DrinkCoverage's
 * sentence does: "here are two" reads as the whole list unless something says
 * there were three.
 */
it('says how many fit, not only how many it handed over', function (): void {
    $output = browsedText(['without_base_spirit' => ['Whiskey'], 'limit' => 1]);

    expect($output)->toContain("2 of the house's drinks fit that")
        ->and($output)->toContain('here are 1 of them')
        ->and($output)->toContain('there are more');
});

it('says how many the house pours when nothing was ruled out', function (): void {
    expect(browsedText())->toStartWith('The house pours 3 drinks; here are 3 of them.');
});

/**
 * The first of three distinct returns. A bare "[]" reads to a model as "the
 * house pours nothing like that", which is a false claim about the menus.
 */
it('tells the model to say so plainly when nothing fits', function (): void {
    $output = browsedText(['base_spirit' => ['Gin'], 'temperature' => 'Hot']);

    expect($output)->toContain('Nothing the house pours fits that')
        ->and($output)->toContain('not on the menu')
        ->and($output)->not->toContain('[');
});

/**
 * The second. An empty catalog and a filter that matched nothing are different
 * facts, and collapsing them would have Sasha report an absence from the menus
 * when what happened is that nobody imported them.
 */
it('distinguishes an unloaded house from a question nothing matched', function (): void {
    useHouseFixture();

    $output = (string) menuTool()->handle(new Request([]));

    expect($output)->toContain('have not been loaded yet')
        ->and($output)->toContain('do not name a drink');
});

/**
 * The third. A catalog outage costs a capability, never an exception in the
 * middle of answering a guest -- the doctrine AiReranker and SurveyTheBooks set.
 */
it('fails closed with a sentence rather than throwing', function (): void {
    importHouse();

    DB::statement('drop table house_cocktail_tags cascade');

    $output = (string) menuTool()->handle(new Request(['base_spirit' => ['Gin']]));

    expect($output)->toContain('unavailable just now')
        ->and($output)->toContain('do not name a drink you have not confirmed');
});

/**
 * A word the house does not know and a drink the house does not pour are
 * different answers. Without this, asking for scotch returns nothing and Sasha
 * tells a guest the house has nothing like it -- true about the wrong thing,
 * since there is no Scotch facet at all and the house does pour whiskey.
 */
it('names a facet value the house does not have rather than reporting an absence', function (): void {
    $output = browsedText(['base_spirit' => ['Scotch']]);

    expect($output)->toContain('nothing filed under "Scotch"')
        ->and($output)->toContain('say so rather than treating it as an answer');
});

it('names an unknown ingredient and an unknown menu too', function (): void {
    expect(browsedText(['without_ingredient' => ['Falernum']]))->toContain('nothing filed under "Falernum"')
        ->and(browsedText(['menu' => 'Winter Menu']))->toContain('nothing filed under "Winter Menu"');
});

it('says nothing about unrecognised values when every word landed', function (): void {
    expect(browsedText(['base_spirit' => ['Gin']]))->not->toContain('nothing filed under');
});

it('describes itself so the model reaches for it on a negation', function (): void {
    $description = (string) menuTool()->description();

    expect($description)->toContain('without')
        ->and($description)->toContain('rules something out')
        ->and($description)->toContain('Every cocktail you name to a guest');
});

/**
 * Nothing is required: "what's good tonight?" has to be a legal call.
 */
it('requires nothing in its schema and offers both halves of every facet', function (): void {
    $schema = menuTool()->schema(new JsonSchemaTypeFactory);

    foreach ($schema as $property) {
        expect($property->toArray())->not->toHaveKey('required');
    }

    expect(array_keys($schema))->toEqualCanonicalizing([
        'base_spirit', 'without_base_spirit', 'flavor', 'style', 'origin',
        'with_ingredient', 'without_ingredient', 'alcohol_level', 'technique',
        'temperature', 'prep_time', 'menu', 'limit',
    ])->and($schema['without_base_spirit']->toArray()['description'])
        ->toContain("I don't like whiskey");
});
