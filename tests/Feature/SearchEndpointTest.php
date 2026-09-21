<?php

use App\Agents\EddieAgent;
use App\Http\Middleware\VerifyBarKey;
use App\Models\Book;
use App\Models\BookChunk;
use App\Models\HouseChunk;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Embeddings;

/**
 * /api/search: the one address that is allowed to hand a passage payload
 * straight to a caller, because it never asks a language model anything.
 *
 * It carries two independent locks rather than one. bar.key is the same door
 * every other route behind it uses; config('app.debug') is a second one that
 * does not care what key was presented, because a debug tool that only checked
 * the key would become a production data-exposure surface the moment a key
 * leaked, rather than the moment somebody also forgot to turn debug off.
 */
beforeEach(function (): void {
    config()->set('bar.api.keys', ['the-house-key']);
    config()->set('app.debug', true);

    // Retrieval is under test elsewhere (ChunkRetrieverTest, HouseRetrieverTest);
    // here only the route needs a passage to come back, so reranking is pinned
    // off and the query is embedded onto the same axis as the chunks in play.
    //
    // Turned off through each corpus's own configuration rather than by
    // rebinding Reranker::class: HouseRetriever takes a *contextual* binding in
    // AppServiceProvider, so a global rebind would reach the books and quietly
    // leave the house reranking against an unreachable TEI.
    config()->set('books.retrieval.rerank.enabled', false);
    config()->set('house.retrieval.rerank.enabled', false);

    Embeddings::fake(fn ($prompt): array => array_map(
        fn (): array => unitVector(1),
        $prompt->inputs,
    ));
});

function search(array $body, ?string $key = 'the-house-key'): TestResponse
{
    return test()->postJson(
        '/api/search',
        $body,
        $key === null ? [] : [VerifyBarKey::HEADER => $key],
    );
}

it('returns the raw passages the retriever found, in debug mode with the right key', function (): void {
    $book = Book::factory()->create(['title' => 'A Test Book', 'year' => 1900]);

    $match = BookChunk::factory()->for($book)->embedded(unitVector(1))->create([
        'text' => 'Half Blue Curaçao, a quarter gin, shake and strain.',
    ]);

    $response = search(['question' => 'a bitter aperitif', 'bartender' => 'eddie'])
        ->assertOk();

    $passages = (array) $response->json('passages');

    expect($passages)->toHaveCount(1)
        ->and($passages[0]['citation'])->toBe($match->citation)
        ->and(array_keys($passages[0]))->toHaveCount(8);
});

/**
 * The claim the whole controller is built on: the registry row decides the
 * corpus, so a bartender can never be paired with the other one's passages.
 *
 * Both corpora hold a chunk on the same axis as the query, so an
 * implementation that hard-coded ChunkRetriever would answer this with the
 * book and fail here rather than passing quietly. .ai/rules/config.md names
 * that wrong-corpus pairing as the failure this shape exists to prevent, and
 * BarAskCommandTest pins the same property for the CLI.
 */
it('searches the house when sasha is asked, not the books', function (): void {
    $book = Book::factory()->create(['title' => 'A Test Book', 'year' => 1900]);

    BookChunk::factory()->for($book)->embedded(unitVector(1))->create([
        'text' => 'A passage from the shelf, which Sasha has no business returning.',
    ]);

    $match = HouseChunk::factory()->embedded(unitVector(1))->create([
        'title' => 'Midnight Rambler',
        'text' => 'Rye, amaro and a long strip of orange.',
        'keywords' => [],
    ]);

    $response = search(['question' => 'something dark and stirred', 'bartender' => 'sasha'])
        ->assertOk();

    $passages = (array) $response->json('passages');

    expect($passages)->toHaveCount(1)
        ->and($passages[0]['citation'])->toBe($match->citation);

    expect($response->getContent())->not->toContain('no business returning');
});

it('calls only the retriever, never an agent', function (): void {
    EddieAgent::fake([]);

    search(['question' => 'a bitter aperitif', 'bartender' => 'eddie'])->assertOk();

    EddieAgent::assertNeverPrompted();
});

it('refuses a caller with no key, even in debug mode', function (): void {
    search(['question' => 'a bitter aperitif', 'bartender' => 'eddie'], null)
        ->assertUnauthorized();
});

it('refuses a caller with the wrong key, even in debug mode', function (): void {
    search(['question' => 'a bitter aperitif', 'bartender' => 'eddie'], 'not-the-house-key')
        ->assertUnauthorized();
});

it('answers 404 when debug is off, regardless of the key', function (): void {
    config()->set('app.debug', false);

    search(['question' => 'a bitter aperitif', 'bartender' => 'eddie'])
        ->assertNotFound();
});

it('answers 404 before validating the body when debug is off', function (): void {
    config()->set('app.debug', false);

    search([])->assertNotFound();
});

it('validates the question and the bartender', function (): void {
    search(['question' => '', 'bartender' => 'eddie'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['question']);

    search(['question' => 'a bitter aperitif', 'bartender' => 'margo'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bartender']);

    search(['question' => 'a bitter aperitif'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bartender']);
});

/**
 * The cap on how large, matching the one BarAskController documents. Nothing
 * here is billed by the token, but the string is embedded through TEI and then
 * reranked against every candidate, so an unbounded one is wall-clock with no
 * ceiling on a host where that path is already the slowest in the application.
 */
it('refuses a question longer than the cap', function (): void {
    search(['question' => str_repeat('a', 2001), 'bartender' => 'eddie'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['question']);

    search(['question' => str_repeat('a', 2000), 'bartender' => 'eddie'])
        ->assertOk();
});

/**
 * The cap on how often. Pinned on the real route rather than on the limiter,
 * because a named limiter nobody attached is the failure mode being guarded
 * against -- this endpoint carried no throttle at all until it was asked for.
 */
it('caps how often it can be called', function (): void {
    config()->set('bar.api.rate_limit', 2);

    search(['question' => 'a bitter aperitif', 'bartender' => 'eddie'])->assertOk();
    search(['question' => 'a bitter aperitif', 'bartender' => 'eddie'])->assertOk();

    search(['question' => 'a bitter aperitif', 'bartender' => 'eddie'])->assertStatus(429);
});

/**
 * One allowance per caller across both expensive routes, not one each.
 *
 * Deliberate, and worth pinning: giving this endpoint a limiter of its own
 * would read as tidier and would silently hand every key twice the per-minute
 * allowance the configuration names.
 */
it('spends the same per-caller allowance as the ask endpoint', function (): void {
    config()->set('bar.api.rate_limit', 2);

    EddieAgent::fake(['Never mind the words, friend.']);

    test()->postJson('/api/ask', [
        'question' => 'what goes in a sazerac?',
        'bartender' => 'eddie',
    ], [VerifyBarKey::HEADER => 'the-house-key'])->assertOk();

    search(['question' => 'a bitter aperitif', 'bartender' => 'eddie'])->assertOk();

    search(['question' => 'a bitter aperitif', 'bartender' => 'eddie'])->assertStatus(429);
});
