<?php

use App\Models\Book;
use App\Models\BookChunk;
use Illuminate\Support\Facades\DB;

/**
 * The schema half of Step 3's one-way doors.
 *
 * Three things here can only be changed by rewriting 26,466 rows and rebuilding
 * an index, and two of them fail quietly rather than loudly when they drift:
 * a vector column whose width no longer matches the configured model, an HNSW
 * index built for the wrong distance operator, and a lexical index that indexes
 * NULL for most of the corpus. Each gets a test so the failure arrives at edit
 * time instead of at retrieval time.
 */
it('sizes the vector column to the configured dimensions', function (): void {
    // Read as Postgres renders it -- "vector(1024)" -- rather than off
    // atttypmod, whose encoding is pgvector's business and not this test's.
    $type = DB::scalar(<<<'SQL'
        select format_type(a.atttypid, a.atttypmod)
        from pg_attribute a
        join pg_class c on c.oid = a.attrelid
        where c.relname = 'book_chunks' and a.attname = 'embedding'
    SQL);

    expect($type)->toBe('vector('.config('books.embedding.dimensions').')');
});

/**
 * whereVectorSimilarTo() compiles to <=> and converts minSimilarity as
 * 1 - similarity, which is only meaningful for cosine. An l2_ops index ranks
 * almost right, which is harder to notice than ranking wrong.
 */
it('indexes the vector for cosine distance', function (): void {
    $definition = DB::scalar(
        "select indexdef from pg_indexes where indexname = 'book_chunks_embedding_hnsw'"
    );

    expect($definition)->toContain('hnsw')
        ->and($definition)->toContain('vector_cosine_ops')
        ->and($definition)->not->toContain('vector_l2_ops');
});

it('indexes the search vector with gin', function (): void {
    $definition = DB::scalar(
        "select indexdef from pg_indexes where indexname = 'book_chunks_search_vector_gin'"
    );

    expect($definition)->toContain('gin')
        ->and($definition)->toContain('search_vector');
});

/**
 * The NULL-propagation pin, and the reason the generated column is written by
 * hand rather than with $table->fullText().
 *
 * compileFulltext() emits to_tsvector(cfg, a) || to_tsvector(cfg, b) with no
 * coalesce, and NULL propagates through ||. Only 56% of chunks have a heading
 * and 36% a section_title, so with fullText() 64% of the corpus would index as
 * NULL and never match anything, with no error anywhere to say so.
 */
it('still indexes a chunk with no heading and no section title', function (): void {
    $book = Book::factory()->create(['title' => 'A Book', 'year' => 1900]);

    BookChunk::factory()->for($book)->create([
        'heading' => null,
        'section_title' => null,
        'headings' => null,
        'text' => 'Half a wineglass of Curaçao, shaken with ice and strained.',
    ]);

    $found = BookChunk::query()
        ->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", ['curaçao'])
        ->count();

    expect($found)->toBe(1);
});

/**
 * A packed block of ten Cafe Royal recipes names all ten. Without headings in
 * the index only the first drink in the block would be findable by name.
 */
it('indexes every heading a packed chunk contains', function (): void {
    $book = Book::factory()->create();

    BookChunk::factory()->for($book)->create([
        'heading' => 'BLUE LADY',
        'headings' => ['BLUE LADY', 'BLUE PETER', 'BLUE TRAIN'],
        'text' => 'Recipes follow.',
    ]);

    expect(BookChunk::query()
        ->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", ['"blue train"'])
        ->count())->toBe(1);
});

it('weights a heading above the body text', function (): void {
    $book = Book::factory()->create();

    $heading = BookChunk::factory()->for($book)->create([
        'heading' => 'SIDECAR',
        'headings' => ['SIDECAR'],
        'text' => 'One third brandy, one third Cointreau, one third lemon juice.',
    ]);

    $mention = BookChunk::factory()->for($book)->create([
        'heading' => null,
        'headings' => null,
        'text' => 'He climbed out of the sidecar and asked for something bracing.',
    ]);

    $ranked = BookChunk::query()
        ->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", ['sidecar'])
        ->orderByRaw("ts_rank_cd(search_vector, websearch_to_tsquery('english', ?), 32) desc", ['sidecar'])
        ->pluck('id')
        ->all();

    expect($ranked)->toBe([$heading->id, $mention->id]);
});

/**
 * Stored, not triggered: the column recomputes because Postgres recomputes it,
 * so nothing in the application can forget to.
 */
it('recomputes the search vector when the text changes', function (): void {
    $book = Book::factory()->create();
    $chunk = BookChunk::factory()->for($book)->create(['text' => 'Nothing relevant here.']);

    $matches = fn (string $term): int => BookChunk::query()
        ->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", [$term])
        ->count();

    expect($matches('absinthe'))->toBe(0);

    $chunk->update(['text' => 'A dash of absinthe in the glass.']);

    expect($matches('absinthe'))->toBe(1);
});

it('round-trips a vector through the cast', function (): void {
    $book = Book::factory()->create();
    $vector = unitVector(7);

    BookChunk::factory()->for($book)->embedded($vector)->create();

    $stored = BookChunk::query()->sole();

    expect($stored->embedding)->toBe($vector)
        ->and(DB::scalar('select vector_dims(embedding) from book_chunks'))
        ->toBe((int) config('books.embedding.dimensions'));
});

/**
 * One-hot vectors give cosine similarity of exactly 1 or exactly 0, so this
 * asserts the operator class as well as the cast: an l2_ops query would order
 * these differently.
 */
it('orders by cosine similarity against a stored vector', function (): void {
    $book = Book::factory()->create();

    $match = BookChunk::factory()->for($book)->embedded(unitVector(3))->create();
    BookChunk::factory()->for($book)->embedded(unitVector(9))->create();

    $hits = BookChunk::query()
        ->whereVectorSimilarTo('embedding', unitVector(3), 0.99)
        ->pluck('id')
        ->all();

    expect($hits)->toBe([$match->id]);
});

/**
 * The retrieval select must not drag 4 KB of vector and a second copy of the
 * text back for every citation.
 */
it('leaves the vector and the search vector out of a retrieval select', function (): void {
    $book = Book::factory()->create();
    BookChunk::factory()->for($book)->embedded(unitVector(1))->create();

    $chunk = BookChunk::query()->forRetrieval()->sole();

    expect($chunk->getAttributes())->not->toHaveKey('embedding')
        ->and($chunk->getAttributes())->not->toHaveKey('search_vector')
        ->and($chunk->getAttributes())->toHaveKey('text')
        ->and($chunk->embedding_model)->toBe((string) config('books.embedding.model'));
});
