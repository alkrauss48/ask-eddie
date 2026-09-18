<?php

use App\Enums\HouseSourceType;
use App\Models\HouseChunk;
use App\Tools\SearchTheHouse;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    config(['house.retrieval.rerank.enabled' => false]);
});

function houseSearchTool(): SearchTheHouse
{
    return app(SearchTheHouse::class);
}

function searchableHousePage(array $overrides = []): HouseChunk
{
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));

    return HouseChunk::factory()->embedded(unitVector(1))->create($overrides + [
        'source_type' => HouseSourceType::Cocktail,
        'source_slug' => 'midnight-rambler',
        'title' => 'Midnight Rambler',
        'subtitle' => 'Our own house original',
        'text' => "Midnight Rambler\n\n2oz Rye Whiskey\n.5oz Blackberry Syrup\nStirred, up.",
        'keywords' => ['Rye Whiskey', 'Whiskey', 'Fruity'],
        'url' => 'https://thekrausshaus.com/cocktails/midnight-rambler',
    ]);
}

/**
 * The boundary the payload discipline actually crosses. Every element of this
 * JSON is read by a language model as content it may repeat, so a score, a rank
 * or the flattened keyword index in here becomes part of a bartender's answer.
 */
it('returns pages with exactly the five house keys', function (): void {
    searchableHousePage();

    $output = houseSearchTool()->handle(new Request(['query' => 'midnight rambler']));
    $pages = json_decode(substr($output, (int) strpos($output, '[')), true, flags: JSON_THROW_ON_ERROR);

    expect($pages)->toHaveCount(1);

    foreach ($pages as $page) {
        expect(array_keys($page))->toEqualCanonicalizing(['kind', 'title', 'text', 'url', 'citation'])
            ->and($page)->toHaveCount(5);
    }
});

it('leaks no score, rank, keyword index or id anywhere in its output', function (): void {
    searchableHousePage();

    $output = houseSearchTool()->handle(new Request(['query' => 'midnight rambler']));

    foreach (['score', 'rank', 'distance', 'similarity', 'embedding', 'search_vector', 'keywords', '"id"'] as $leak) {
        expect(strtolower($output))->not->toContain($leak);
    }
});

/**
 * A house citation is a link rather than a page number, and it is the thing
 * that makes Sasha's claim checkable: a guest can open it and see that the
 * house pours the drink she just named.
 */
it('carries a link a guest could open', function (): void {
    searchableHousePage();

    $output = houseSearchTool()->handle(new Request(['query' => 'midnight rambler']));
    $pages = json_decode(substr($output, (int) strpos($output, '[')), true, flags: JSON_THROW_ON_ERROR);

    expect($pages[0]['citation'])
        ->toBe('Midnight Rambler (Our own house original) — https://thekrausshaus.com/cocktails/midnight-rambler')
        ->and($pages[0]['kind'])->toBe('Cocktail')
        ->and($pages[0]['url'])->toBe('https://thekrausshaus.com/cocktails/midnight-rambler')
        ->and($pages[0]['text'])->toContain('Blackberry Syrup');
});

it('parses as json after its preamble', function (): void {
    searchableHousePage();

    $output = houseSearchTool()->handle(new Request(['query' => 'midnight rambler']));

    expect($output)->toStartWith('Pages from the house');

    $json = substr($output, (int) strpos($output, '['));

    expect(json_decode($json, true))->toBeArray()
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);
});

/**
 * A bartender who says "we don't pour that" is the whole point of grounding
 * Sasha in the menus. The empty case has to say so in a way the model will act
 * on, not return "[]".
 */
it('tells the model to say so rather than invent when nothing matches', function (): void {
    HouseChunk::factory()->embedded(unitVector(800))->create(['text' => 'Unrelated.', 'keywords' => []]);

    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));

    $output = houseSearchTool()->handle(new Request(['query' => 'a drink the house does not pour']));

    expect($output)->toContain('Nothing on the house pages')
        ->and($output)->toContain('rather than inventing a drink');
});

it('handles a blank query without calling the provider', function (): void {
    Embeddings::fake()->preventStrayEmbeddings();

    expect(houseSearchTool()->handle(new Request(['query' => '  '])))->toBe('No search query was given.');
});

/**
 * The house corpus needs TEI to embed a query, and a container that is down
 * must cost Sasha a capability rather than land an exception in the middle of a
 * conversation. Same doctrine as AiReranker and SurveyTheBooks.
 */
it('fails closed with a sentence when the embedder is unreachable', function (): void {
    searchableHousePage();

    Embeddings::fake(fn (): never => throw new RuntimeException('Connection refused'));

    $output = houseSearchTool()->handle(new Request(['query' => 'midnight rambler']));

    expect($output)->toContain('unavailable just now')
        ->and($output)->toContain('do not name a drink you have not confirmed');
});

it('describes itself in terms that send the which-drink question elsewhere', function (): void {
    $description = (string) houseSearchTool()->description();

    expect($description)->toContain('recipe')
        ->and($description)->toContain('use the menu tool when the question is which');
});

it('requires a query in its schema', function (): void {
    $schema = houseSearchTool()->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKey('query')
        ->and($schema['query']->toArray()['description'])->toContain('drink name');
});
