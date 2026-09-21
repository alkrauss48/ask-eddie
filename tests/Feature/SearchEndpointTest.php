<?php

use App\Agents\EddieAgent;
use App\Http\Middleware\VerifyBarKey;
use App\Models\Book;
use App\Models\BookChunk;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
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

    // Retrieval is under test elsewhere (ChunkRetrieverTest); here only the
    // route needs a passage to come back, so reranking is pinned off and the
    // query is embedded onto the same axis as the one chunk in play.
    app()->bind(Reranker::class, fn (): Reranker => new NullReranker);
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
