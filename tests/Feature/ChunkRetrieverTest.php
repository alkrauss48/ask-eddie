<?php

use App\Models\Book;
use App\Models\BookChunk;
use App\Services\Retrieval\ChunkRetriever;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
use App\Services\Retrieval\RetrievedChunk;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

beforeEach(function (): void {
    // Reranking is a separate stage with its own test; here the fused order is
    // what is under test, so it must reach the assertions unrearranged.
    app()->bind(Reranker::class, fn (): Reranker => new NullReranker);
});

function retrievableBook(): Book
{
    return Book::factory()->create(['title' => 'A Test Book', 'year' => 1900]);
}

/**
 * Embed the query onto one axis and each chunk onto whichever axis it is given,
 * so cosine similarity is exactly 1 or exactly 0 and the dense channel's
 * membership is a fact rather than a probability.
 */
function fakeQueryVector(int $axis): void
{
    Embeddings::fake(fn ($prompt): array => array_map(
        fn (): array => unitVector($axis),
        $prompt->inputs,
    ));
}

it('finds a chunk on the dense channel alone', function (): void {
    $book = retrievableBook();

    $match = BookChunk::factory()->for($book)->embedded(unitVector(1))->create([
        'text' => 'Nothing in this passage shares a word with the question.',
    ]);
    BookChunk::factory()->for($book)->embedded(unitVector(2))->create(['text' => 'Nor this one.']);

    fakeQueryVector(1);

    $results = app(ChunkRetriever::class)->retrieve('a bitter aperitif');

    expect($results)->toHaveCount(1)
        ->and($results[0]->chunk->id)->toBe($match->id)
        ->and($results[0]->rankIn(ChunkRetriever::DENSE))->toBe(1)
        ->and($results[0]->rankIn(ChunkRetriever::LEXICAL))->toBeNull();
});

it('finds a chunk on the lexical channel alone', function (): void {
    $book = retrievableBook();

    $match = BookChunk::factory()->for($book)->embedded(unitVector(5))->create([
        'heading' => 'BLUE LADY',
        'headings' => ['BLUE LADY'],
        'text' => 'Half Blue Curaçao, a quarter gin, shake and strain.',
    ]);

    // Orthogonal to the query, so the dense channel cannot see it.
    fakeQueryVector(900);

    $results = app(ChunkRetriever::class)->retrieve('"blue lady"');

    expect($results)->toHaveCount(1)
        ->and($results[0]->chunk->id)->toBe($match->id)
        ->and($results[0]->rankIn(ChunkRetriever::LEXICAL))->toBe(1)
        ->and($results[0]->rankIn(ChunkRetriever::DENSE))->toBeNull();
});

/**
 * The property the hybrid design is bought for, end to end: a passage both
 * channels found beats one each found alone.
 */
it('ranks a chunk both channels found above either channel winner', function (): void {
    $book = retrievableBook();

    $denseOnly = BookChunk::factory()->for($book)->embedded(unitVector(1))->create([
        'text' => 'A passage with no shared vocabulary whatsoever.',
    ]);
    $both = BookChunk::factory()->for($book)->embedded(unitVector(1))->create([
        'heading' => 'SIDECAR',
        'headings' => ['SIDECAR'],
        'text' => 'Brandy, Cointreau and lemon, shaken hard.',
    ]);
    $lexicalOnly = BookChunk::factory()->for($book)->embedded(unitVector(600))->create([
        'text' => 'He asked for a sidecar and got a lecture instead.',
    ]);

    fakeQueryVector(1);

    $order = app(ChunkRetriever::class)->retrieve('sidecar')
        ->map(fn (RetrievedChunk $r): int => $r->chunk->id)
        ->all();

    expect($order[0])->toBe($both->id)
        ->and($order)->toContain($denseOnly->id)
        ->and($order)->toContain($lexicalOnly->id);
});

/**
 * Nothing is deleted for being unindexable, so this predicate is the only thing
 * keeping an index page or a page of scanner noise out of an answer -- on both
 * channels, including an exact text match.
 */
it('never returns a non-indexable chunk, even on an exact text match', function (): void {
    $book = retrievableBook();

    BookChunk::factory()->for($book)->excluded()->embedded(unitVector(1))->create([
        'heading' => 'BLUE LADY',
        'headings' => ['BLUE LADY'],
        'text' => 'BLUE LADY ... 42',
    ]);

    fakeQueryVector(1);

    expect(app(ChunkRetriever::class)->retrieve('"blue lady"'))->toBeEmpty();
});

/**
 * Both channels must draw from the same universe. A lexical list that can see
 * unembedded chunks fused with a dense list that cannot would let a passage rank
 * purely because the other channel was structurally unable to consider it.
 */
it('never returns a chunk that has not been embedded', function (): void {
    $book = retrievableBook();

    BookChunk::factory()->for($book)->create([
        'heading' => 'BLUE LADY',
        'headings' => ['BLUE LADY'],
        'text' => 'Half Blue Curaçao, a quarter gin.',
    ]);

    fakeQueryVector(1);

    expect(app(ChunkRetriever::class)->retrieve('"blue lady"'))->toBeEmpty();
});

/**
 * The silent catastrophe this configuration exists to prevent: the query
 * embedded by a different model than the corpus, with no exception raised and
 * plausible-looking output.
 */
it('embeds the query with the corpus own provider and model', function (): void {
    $book = retrievableBook();
    BookChunk::factory()->for($book)->embedded(unitVector(1))->create();

    fakeQueryVector(1);

    app(ChunkRetriever::class)->retrieve('anything at all');

    Embeddings::assertGenerated(
        fn ($prompt): bool => $prompt->model === (string) config('books.embedding.model')
            && $prompt->dimensions === (int) config('books.embedding.dimensions')
            && $prompt->inputs === ['anything at all']
    );
});

it('honours the configured candidate ceiling per channel', function (): void {
    $book = retrievableBook();

    BookChunk::factory()->count(6)->for($book)->embedded(unitVector(1))->create([
        'text' => 'A passage about gin.',
    ]);

    config(['books.retrieval.dense_candidates' => 2, 'books.retrieval.lexical_candidates' => 2]);
    fakeQueryVector(1);

    $retriever = app(ChunkRetriever::class);

    expect($retriever->dense('gin'))->toHaveCount(2)
        ->and($retriever->lexical('gin'))->toHaveCount(2);
});

it('takes only the configured number of passages', function (): void {
    $book = retrievableBook();
    BookChunk::factory()->count(12)->for($book)->embedded(unitVector(1))->create();

    config(['books.retrieval.limit' => 3]);
    fakeQueryVector(1);

    expect(app(ChunkRetriever::class)->retrieve('gin'))->toHaveCount(3);
});

/**
 * One embed, one dense select, one lexical select, one hydrate. A retriever that
 * hydrates per hit would N+1 the whole page of citations.
 */
it('costs four queries', function (): void {
    $book = retrievableBook();
    BookChunk::factory()->count(5)->for($book)->embedded(unitVector(1))->create([
        'text' => 'A passage about gin.',
    ]);

    fakeQueryVector(1);

    DB::enableQueryLog();
    app(ChunkRetriever::class)->retrieve('gin');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // The ef_search statement, the dense select, the lexical select, the
    // hydrate, and the eager-loaded books that keep citations off an N+1.
    expect($queries)->toHaveCount(5);
});

it('retrieves nothing for a blank question without touching the provider', function (): void {
    $book = retrievableBook();
    BookChunk::factory()->for($book)->embedded(unitVector(1))->create();

    Embeddings::fake()->preventStrayEmbeddings();

    expect(app(ChunkRetriever::class)->retrieve('   '))->toBeEmpty();
});

/**
 * A stranger's typing must not reach tsquery unfiltered.
 * websearch_to_tsquery never throws on malformed input; plainto_tsquery's
 * cousins do.
 */
it('survives a question full of query syntax', function (): void {
    $book = retrievableBook();
    BookChunk::factory()->for($book)->embedded(unitVector(1))->create(['text' => 'Gin and it.']);

    fakeQueryVector(1);

    expect(fn (): mixed => app(ChunkRetriever::class)->retrieve('gin & | ! ( "unclosed'))
        ->not->toThrow(Throwable::class);
});

it('keeps retrieval metadata off the payload', function (): void {
    $book = retrievableBook();
    BookChunk::factory()->for($book)->embedded(unitVector(1))->create();

    fakeQueryVector(1);

    $payload = app(ChunkRetriever::class)->retrieve('gin')->first()->payload();

    expect($payload)->toHaveCount(8)
        ->and($payload)->not->toHaveKey('score')
        ->and($payload)->not->toHaveKey('embedding')
        ->and($payload)->not->toHaveKey('search_vector');
});
