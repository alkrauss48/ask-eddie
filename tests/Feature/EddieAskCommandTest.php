<?php

use App\Agents\EddieAgent;
use App\Models\Book;
use App\Models\BookChunk;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
use App\Tools\SearchTheBooks;
use Laravel\Ai\Embeddings;

beforeEach(function (): void {
    app()->bind(Reranker::class, fn (): Reranker => new NullReranker);
});

function askableCorpus(): BookChunk
{
    $book = Book::factory()->create([
        'slug' => 'old-waldorf-bar-days-1931',
        'title' => 'Old Waldorf Bar Days',
        'year' => 1931,
    ]);

    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));

    return BookChunk::factory()->for($book)->embedded(unitVector(1))->create([
        'heading' => 'BLUE LADY',
        'headings' => ['BLUE LADY'],
        'section_title' => null,
        'text' => "BLUE LADY 1/2 Blue Curaçao.\n1/4 Booth's Gin.\nShake and strain.",
        'page_from' => 119,
        'page_to' => 119,
        'printed_page_from' => '107',
        'printed_page_to' => '107',
    ]);
}

/**
 * The table truncates to the terminal width, so this asserts the columns the
 * command uniquely adds rather than the citation text -- which is pinned
 * against truncation in BookChunkCitationTest and SearchTheBooksToolTest.
 */
it('names the book and both channels for a retrieved passage', function (): void {
    askableCorpus();

    $this->artisan('eddie:ask', [
        'question' => ['what', 'goes', 'in', 'a', 'Blue', 'Lady?'],
        '--retrieval-only' => true,
    ])
        // Each expectation is matched against a single write, and the channel
        // summary arrives as one, so this asserts the table and the summary
        // rather than both channel lines separately.
        ->expectsOutputToContain('Old Waldorf Bar Days')
        ->expectsOutputToContain('dense: 1 of 1')
        ->assertSuccessful();
});

/**
 * The reason the per-channel ranks survive fusion at all. A hybrid search where
 * one channel silently returns nothing answers questions perfectly well,
 * slightly worse, and no single answer reveals it.
 */
it('says out loud when a channel contributed nothing', function (): void {
    $book = Book::factory()->create(['title' => 'A Book', 'year' => 1900]);
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));

    // Found by the vector, but sharing no word with the question.
    BookChunk::factory()->for($book)->embedded(unitVector(1))->create([
        'heading' => null,
        'headings' => null,
        'text' => 'Something else entirely.',
    ]);

    $this->artisan('eddie:ask', [
        'question' => ['absinthe'],
        '--retrieval-only' => true,
    ])
        ->expectsOutputToContain('contributed nothing')
        ->assertSuccessful();
});

it('fails when nothing is retrieved', function (): void {
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));

    $this->artisan('eddie:ask', [
        'question' => ['anything', 'at', 'all'],
        '--retrieval-only' => true,
    ])
        ->expectsOutputToContain('Nothing retrieved')
        ->assertFailed();
});

it('honours an explicit limit', function (): void {
    $book = Book::factory()->create(['title' => 'A Book', 'year' => 1900]);
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));
    BookChunk::factory()->count(6)->for($book)->embedded(unitVector(1))->create(['text' => 'Gin.']);

    $this->artisan('eddie:ask', [
        'question' => ['gin'],
        '--retrieval-only' => true,
        '--limit' => 2,
    ])->assertSuccessful()
        // Two rows and no third.
        ->doesntExpectOutputToContain('| 3     |');
});

/**
 * A retrieval failure must name the likely cause rather than surfacing a raw
 * connection exception, because the likely cause is always the same one.
 */
it('reports a retrieval failure without a stack trace', function (): void {
    Embeddings::fake(fn (): never => throw new RuntimeException('Connection refused'));

    $this->artisan('eddie:ask', [
        'question' => ['gin'],
        '--retrieval-only' => true,
    ])
        ->expectsOutputToContain('Retrieval failed')
        ->expectsOutputToContain('books:doctor')
        ->assertFailed();
});

it('gives eddie the search tool and nothing else', function (): void {
    $tools = iterator_to_array(app(EddieAgent::class)->tools());

    expect($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(SearchTheBooks::class);
});

/**
 * The two halves of the persona are in tension on purpose -- "invent one on the
 * spot" against "never attribute anything to a book you did not read here" --
 * so the boundary between them is stated rather than left to the model to
 * infer. Losing either half loses the product.
 */
it('tells eddie to search before answering and never to invent a source', function (): void {
    $instructions = (string) app(EddieAgent::class)->instructions();

    expect($instructions)->toContain('You are Eddie')
        // Substrings that do not span the heredoc's line wraps.
        ->and($instructions)->toContain('search tool before answering')
        ->and($instructions)->toContain('Never attribute a drink')
        ->and($instructions)->toContain('Inventing a drink is part of the job');
});
