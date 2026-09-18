<?php

use App\Enums\HouseSourceType;
use App\Models\HouseChunk;
use App\Services\Retrieval\AiReranker;
use App\Services\Retrieval\HouseRetriever;
use App\Services\Retrieval\HybridRetriever;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
use App\Services\Retrieval\RetrievedChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;

beforeEach(function (): void {
    // Reranking is its own stage with its own test; here the fused order is
    // what is under test, so it must reach the assertions unrearranged. Turned
    // off through the house's own configuration rather than by rebinding, so
    // the contextual binding in AppServiceProvider is the thing being exercised.
    config(['house.retrieval.rerank.enabled' => false]);
});

/**
 * Embed the query onto one axis so cosine similarity is exactly 1 or exactly 0
 * and the dense channel's membership is a fact rather than a probability.
 */
function houseQueryVector(int $axis): void
{
    Embeddings::fake(fn ($prompt): array => array_map(
        fn (): array => unitVector($axis),
        $prompt->inputs,
    ));
}

function houseRetriever(): HouseRetriever
{
    return app(HouseRetriever::class);
}

it('finds a house page on the dense channel alone', function (): void {
    $match = HouseChunk::factory()->embedded(unitVector(1))->create([
        'title' => 'Midnight Rambler',
        'text' => 'Nothing in this page shares a word with the question.',
        'keywords' => [],
    ]);
    HouseChunk::factory()->embedded(unitVector(2))->create(['text' => 'Nor this one.', 'keywords' => []]);

    houseQueryVector(1);

    $results = houseRetriever()->retrieve('something dark and stirred');

    expect($results)->toHaveCount(1)
        ->and($results[0]->chunk->id)->toBe($match->id)
        ->and($results[0]->rankIn(HybridRetriever::DENSE))->toBe(1)
        ->and($results[0]->rankIn(HybridRetriever::LEXICAL))->toBeNull();
});

/**
 * The keyword column is the reason the 131 uncounted ingredients are findable
 * at all: an ingredient never becomes a chunk, and its title reaches retrieval
 * as B-weighted vocabulary on the drinks that pour it.
 */
it('finds a drink by an ingredient that is only in its keywords', function (): void {
    $match = HouseChunk::factory()->embedded(unitVector(5))->create([
        'title' => 'Hot Buttered Rum',
        'text' => 'Built in a mug, topped with boiling water.',
        'keywords' => ['Smith and Cross', 'Jamaican Rum', 'Spiced'],
    ]);

    // Orthogonal to the query, so the dense channel cannot see it.
    houseQueryVector(900);

    $results = houseRetriever()->retrieve('"smith and cross"');

    expect($results)->toHaveCount(1)
        ->and($results[0]->chunk->id)->toBe($match->id)
        ->and($results[0]->rankIn(HybridRetriever::LEXICAL))->toBe(1)
        ->and($results[0]->rankIn(HybridRetriever::DENSE))->toBeNull();
});

it('ranks a page both channels found above either channel winner', function (): void {
    $denseOnly = HouseChunk::factory()->embedded(unitVector(1))->create([
        'title' => 'Some Other Drink',
        'text' => 'A page with no shared vocabulary whatsoever.',
        'keywords' => [],
    ]);
    $both = HouseChunk::factory()->embedded(unitVector(1))->create([
        'title' => 'Sazerac',
        'text' => 'Rye, absinthe rinse, Peychaud\'s.',
        'keywords' => ['Sazerac'],
    ]);
    $lexicalOnly = HouseChunk::factory()->embedded(unitVector(600))->create([
        'title' => 'The Ramble',
        'text' => 'Opens with a Sazerac and ends somewhere warmer.',
        'keywords' => [],
    ]);

    houseQueryVector(1);

    $order = houseRetriever()->retrieve('sazerac')
        ->map(fn (RetrievedChunk $r): int => $r->chunk->id)
        ->all();

    expect($order[0])->toBe($both->id)
        ->and($order)->toContain($denseOnly->id)
        ->and($order)->toContain($lexicalOnly->id);
});

it('never returns a non-indexable page, even on an exact text match', function (): void {
    HouseChunk::factory()->excluded()->embedded(unitVector(1))->create([
        'title' => 'Midnight Rambler',
        'text' => 'Midnight Rambler, withdrawn from the menu.',
        'keywords' => ['Midnight Rambler'],
    ]);

    houseQueryVector(1);

    expect(houseRetriever()->retrieve('"midnight rambler"'))->toBeEmpty();
});

/**
 * Both channels must draw from the same universe. A lexical list that can see
 * unembedded chunks fused with a dense list that cannot would let a page rank
 * purely because the other channel was structurally unable to consider it.
 */
it('never returns a page that has not been embedded', function (): void {
    HouseChunk::factory()->create([
        'title' => 'Midnight Rambler',
        'text' => 'Rye, blackberry, lemon.',
        'keywords' => ['Midnight Rambler'],
    ]);

    houseQueryVector(1);

    expect(houseRetriever()->retrieve('"midnight rambler"'))->toBeEmpty();
});

/**
 * Corpus and query vectors must come from one implementation, and the house has
 * its own configuration block that says so. Reading the books' block here would
 * be invisible in every row: the right width, the right count, self-consistent,
 * and answered by a model that never read this corpus.
 */
it('embeds the query with the house own provider and model', function (): void {
    HouseChunk::factory()->embedded(unitVector(1))->create();

    houseQueryVector(1);

    houseRetriever()->retrieve('anything at all');

    Embeddings::assertGenerated(
        fn ($prompt): bool => $prompt->model === (string) config('house.embedding.model')
            && $prompt->dimensions === (int) config('house.embedding.dimensions')
            && $prompt->inputs === ['anything at all']
    );
});

it('honours the house own candidate ceiling per channel', function (): void {
    HouseChunk::factory()->count(6)->embedded(unitVector(1))->create([
        'text' => 'A page about rum.',
        'keywords' => ['rum'],
    ]);

    config(['house.retrieval.dense_candidates' => 2, 'house.retrieval.lexical_candidates' => 2]);
    houseQueryVector(1);

    $retriever = houseRetriever();

    expect($retriever->dense('rum'))->toHaveCount(2)
        ->and($retriever->lexical('rum'))->toHaveCount(2);
});

it('takes only the house own configured number of pages', function (): void {
    HouseChunk::factory()->count(12)->embedded(unitVector(1))->create();

    config(['house.retrieval.limit' => 3]);
    houseQueryVector(1);

    expect(houseRetriever()->retrieve('rum'))->toHaveCount(3);
});

/**
 * Three, where books costs five. No `set local hnsw.ef_search` because there is
 * no approximate index to tune, and no eager-loaded book because a house
 * citation is a URL rather than a row in another table.
 */
it('costs three queries', function (): void {
    HouseChunk::factory()->count(5)->embedded(unitVector(1))->create([
        'text' => 'A page about rum.',
        'keywords' => ['rum'],
    ]);

    houseQueryVector(1);

    DB::enableQueryLog();
    houseRetriever()->retrieve('rum');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(3)
        // The knob does not exist in config('house'), so emitting the statement
        // would set ef_search to 0 and quietly return nothing.
        ->and(collect($queries)->pluck('query')->implode(' '))->not->toContain('hnsw.ef_search');
});

it('retrieves nothing for a blank question without touching the provider', function (): void {
    HouseChunk::factory()->embedded(unitVector(1))->create();

    Embeddings::fake()->preventStrayEmbeddings();

    expect(houseRetriever()->retrieve('   '))->toBeEmpty();
});

it('survives a question full of query syntax', function (): void {
    HouseChunk::factory()->embedded(unitVector(1))->create(['text' => 'Rum and it.']);

    houseQueryVector(1);

    expect(fn (): mixed => houseRetriever()->retrieve('rum & | ! ( "unclosed'))
        ->not->toThrow(Throwable::class);
});

it('keeps retrieval metadata off the house payload', function (): void {
    HouseChunk::factory()->ofType(HouseSourceType::Cocktail)->embedded(unitVector(1))->create();

    houseQueryVector(1);

    $payload = houseRetriever()->retrieve('rum')->first()->payload();

    expect($payload)->toHaveCount(5)
        ->and($payload)->not->toHaveKey('score')
        ->and($payload)->not->toHaveKey('embedding')
        ->and($payload)->not->toHaveKey('keywords')
        ->and($payload)->not->toHaveKey('search_vector');
});

/**
 * The sharp edge the contextual binding exists for. The Reranker binding is
 * global and reads config('books.retrieval.rerank.enabled'); without the
 * contextual override in AppServiceProvider, turning the books' reranking off
 * on an Apple Silicon dev machine would turn the house's off with it, and the
 * house's candidate ceiling would be unreachable.
 *
 * Asserted through the request the cross-encoder actually received rather than
 * through the bound class, because the binding is only half of it: a reranker
 * resolved correctly and then reading the books' knobs is the same defect
 * wearing a different coat.
 */
it('reranks the house on the house own settings rather than the books', function (): void {
    config([
        'books.retrieval.rerank.enabled' => false,
        'books.retrieval.rerank.candidates' => 40,
        'house.retrieval.rerank.enabled' => true,
        'house.retrieval.rerank.candidates' => 2,
    ]);

    HouseChunk::factory()->count(3)->embedded(unitVector(1))->create([
        'text' => 'A page about rum.',
        'keywords' => ['rum'],
    ]);

    houseQueryVector(1);
    Http::fake(['*/rerank' => Http::response([['index' => 0, 'score' => 0.9]])]);

    houseRetriever()->retrieve('rum');

    // Two, from config('house'), against the books' forty.
    Http::assertSent(fn ($request): bool => count($request['texts']) === 2);

    // And the global binding is still the books', which is off.
    expect(app(Reranker::class))->toBeInstanceOf(NullReranker::class);
});

it('leaves the house unreranked when only the books rerank', function (): void {
    config([
        'books.retrieval.rerank.enabled' => true,
        'house.retrieval.rerank.enabled' => false,
    ]);

    HouseChunk::factory()->count(3)->embedded(unitVector(1))->create([
        'text' => 'A page about rum.',
        'keywords' => ['rum'],
    ]);

    houseQueryVector(1);

    // Any request at all would be the failure; preventStrayRequests() in Pest.php
    // turns one into a named exception rather than a silent pass.
    houseRetriever()->retrieve('rum');

    expect(app(Reranker::class))->toBeInstanceOf(AiReranker::class);
});
