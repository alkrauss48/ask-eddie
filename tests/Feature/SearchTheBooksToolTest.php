<?php

use App\Models\Book;
use App\Models\BookChunk;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
use App\Tools\SearchTheBooks;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    app()->bind(Reranker::class, fn (): Reranker => new NullReranker);
});

function searchTool(): SearchTheBooks
{
    return app(SearchTheBooks::class);
}

function searchableChunk(array $overrides = []): BookChunk
{
    $book = Book::factory()->create([
        'title' => 'Old Waldorf Bar Days',
        'author' => 'Albert Stevens Crockett',
        'year' => 1931,
    ]);

    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));

    return BookChunk::factory()->for($book)->embedded(unitVector(1))->create($overrides + [
        'section_title' => 'Concerning the Curriculum',
        'heading' => 'BLUE LADY',
        'headings' => ['BLUE LADY'],
        'text' => "BLUE LADY 1/2 Blue Curaçao.\n1/4 Booth's Gin.\nShake and strain.",
        'page_from' => 119,
        'page_to' => 119,
        'printed_page_from' => '107',
        'printed_page_to' => '107',
    ]);
}

/**
 * The Phase 5 contract, at the boundary it actually crosses. Every element of
 * this JSON is read by a language model as content it may repeat, so a score or
 * a rank in here becomes "relevance 0.87" in a bartender's answer.
 */
it('returns passages with exactly the eight citation keys', function (): void {
    searchableChunk();

    $output = searchTool()->handle(new Request(['query' => 'blue lady']));
    $passages = json_decode(substr($output, (int) strpos($output, '[')), true, flags: JSON_THROW_ON_ERROR);

    expect($passages)->toHaveCount(1);

    foreach ($passages as $passage) {
        expect(array_keys($passage))->toEqualCanonicalizing([
            'book_title', 'author', 'year', 'section_title', 'heading', 'pages', 'citation', 'text',
        ])->and($passage)->toHaveCount(8);
    }
});

it('leaks no score, rank, distance or id anywhere in its output', function (): void {
    searchableChunk();

    $output = searchTool()->handle(new Request(['query' => 'blue lady']));

    foreach (['score', 'rank', 'distance', 'similarity', 'embedding', 'search_vector', '"id"'] as $leak) {
        expect(strtolower($output))->not->toContain($leak);
    }
});

it('carries the citation a guest could check', function (): void {
    searchableChunk();

    $output = searchTool()->handle(new Request(['query' => 'blue lady']));
    $passages = json_decode(substr($output, (int) strpos($output, '[')), true, flags: JSON_THROW_ON_ERROR);

    expect($passages[0]['citation'])
        ->toBe('Old Waldorf Bar Days (1931), "Concerning the Curriculum", p. 107 (PDF p. 119)')
        ->and($passages[0]['book_title'])->toBe('Old Waldorf Bar Days')
        ->and($passages[0]['year'])->toBe(1931)
        ->and($passages[0]['text'])->toContain('Blue Curaçao');
});

it('parses as json after its preamble', function (): void {
    searchableChunk();

    $output = searchTool()->handle(new Request(['query' => 'blue lady']));

    expect($output)->toStartWith('Passages from the books');

    $json = substr($output, (int) strpos($output, '['));

    expect(json_decode($json, true))->toBeArray()
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);
});

/**
 * A bartender who says "not in my books" is the point of the whole corpus. The
 * empty case has to say so in a way the model will act on, not return "[]".
 */
it('tells the model to say so rather than invent when nothing matches', function (): void {
    $book = Book::factory()->create();
    BookChunk::factory()->for($book)->embedded(unitVector(800))->create(['text' => 'Unrelated.']);

    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));

    $output = searchTool()->handle(new Request(['query' => 'a drink that does not exist']));

    expect($output)->toContain('No passages')
        ->and($output)->toContain('rather than inventing one');
});

it('handles a blank query without calling the provider', function (): void {
    Embeddings::fake()->preventStrayEmbeddings();

    expect(searchTool()->handle(new Request(['query' => '  '])))->toBe('No search query was given.');
});

it('describes itself in terms that make the model reach for it', function (): void {
    $description = (string) searchTool()->description();

    expect($description)->toContain('drink')
        ->and($description)->toContain('cite');
});

it('requires a query in its schema', function (): void {
    $schema = searchTool()->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKey('query')
        ->and($schema['query']->toArray()['description'])->toContain('drink name');
});
