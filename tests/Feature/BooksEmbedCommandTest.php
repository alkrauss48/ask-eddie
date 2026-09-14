<?php

use App\Models\Book;
use App\Models\BookChunk;
use App\Services\Books\ChunkEmbedder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

/**
 * A book with a recipe chunk, an excluded chunk, and whatever else is asked for.
 *
 * @param  int  $indexable  how many indexable chunks to give it
 */
function embeddableBook(string $slug = 'test-book-1900', int $indexable = 3, int $excluded = 1): Book
{
    $book = Book::factory()->create([
        'slug' => $slug,
        'title' => 'A Test Book',
        'year' => 1900,
    ]);

    BookChunk::factory()->count($indexable)->for($book)->recipe()->create();
    BookChunk::factory()->count($excluded)->for($book)->excluded()->create();

    return $book;
}

it('embeds every indexable chunk and leaves the rest alone', function (): void {
    fakeEmbeddings();
    $book = embeddableBook();

    $this->artisan('books:embed', ['--book' => ['test-book-1900']])->assertSuccessful();

    expect($book->chunks()->where('is_indexable', true)->whereNotNull('embedding')->count())->toBe(3)
        // Nothing is deleted for being unindexable, so this is the only thing
        // keeping an index page out of the vector store.
        ->and($book->chunks()->where('is_indexable', false)->whereNotNull('embedding')->count())->toBe(0);
});

/**
 * The direct regression guard for the Python original, which lifted the heading
 * out of the body and then embedded the body alone -- leaving "Blue Lady"
 * unsearchable in a book that is nothing but drink names.
 */
it('embeds the provenance-prefixed string rather than the bare text', function (): void {
    fakeEmbeddings();
    embeddableBook(indexable: 1, excluded: 0);

    $this->artisan('books:embed')->assertSuccessful();

    Embeddings::assertGenerated(
        fn ($prompt): bool => str_starts_with($prompt->inputs[0], 'A Test Book (1900) —')
            && str_contains($prompt->inputs[0], 'BLUE LADY')
    );
});

it('records the model, dimensions and embedder version on every row', function (): void {
    fakeEmbeddings();
    embeddableBook(indexable: 2, excluded: 0);

    $this->artisan('books:embed')->assertSuccessful();

    $chunk = BookChunk::query()->whereNotNull('embedding')->first();

    expect($chunk->embedding_model)->toBe('BAAI/bge-m3')
        ->and($chunk->embedding_dimensions)->toBe(1024)
        ->and($chunk->embedder_version)->toBe(ChunkEmbedder::VERSION)
        ->and($chunk->embedded_at)->not->toBeNull()
        ->and(DB::scalar('select vector_dims(embedding) from book_chunks where id = ?', [$chunk->id]))->toBe(1024);
});

it('honours the batch size', function (): void {
    fakeEmbeddings();
    embeddableBook(indexable: 5, excluded: 0);

    $sizes = [];
    Embeddings::fake(function ($prompt) use (&$sizes): array {
        $sizes[] = count($prompt->inputs);

        return array_map(fn (): array => Embeddings::fakeEmbedding(1024), $prompt->inputs);
    });

    $this->artisan('books:embed', ['--batch' => 2])->assertSuccessful();

    expect($sizes)->toBe([2, 2, 1]);
});

it('generates nothing on a second run', function (): void {
    fakeEmbeddings();
    embeddableBook(indexable: 3, excluded: 0);

    $this->artisan('books:embed')->assertSuccessful();

    // Counted rather than asserted with assertNothingGenerated(), which reads a
    // recording that accumulates across both runs in a single test.
    $calls = 0;
    Embeddings::fake(function ($prompt) use (&$calls): array {
        $calls++;

        return array_map(fn (): array => Embeddings::fakeEmbedding(1024), $prompt->inputs);
    });

    $this->artisan('books:embed')
        ->expectsOutputToContain('already carries a current vector')
        ->assertSuccessful();

    expect($calls)->toBe(0);
});

it('re-embeds a current corpus when forced', function (): void {
    fakeEmbeddings();
    embeddableBook(indexable: 2, excluded: 0);

    $this->artisan('books:embed')->assertSuccessful();
    $before = BookChunk::query()->whereNotNull('embedding')->pluck('embedding', 'id')->all();

    fakeEmbeddings();
    $this->artisan('books:embed', ['--force' => true])->assertSuccessful();

    $after = BookChunk::query()->whereNotNull('embedding')->pluck('embedding', 'id')->all();

    expect(array_keys($after))->toBe(array_keys($before))
        ->and($after)->not->toBe($before);
});

/**
 * The reason the model name is recorded per row rather than assumed: switching
 * models has to make the corpus pending without anyone remembering to pass a
 * flag. Otherwise queries embed with one model and the corpus with another, and
 * nothing anywhere raises.
 */
it('treats a model change as pending without being forced', function (): void {
    fakeEmbeddings();
    embeddableBook(indexable: 2, excluded: 0);

    $this->artisan('books:embed')->assertSuccessful();

    config(['books.embedding.model' => 'BAAI/bge-m3-v2']);
    fakeEmbeddings();

    $this->artisan('books:embed')->assertSuccessful();

    expect(BookChunk::query()->where('embedding_model', 'BAAI/bge-m3-v2')->count())->toBe(2);
});

it('restricts itself to the named books', function (): void {
    fakeEmbeddings();
    embeddableBook('first-book-1900', indexable: 2, excluded: 0);
    $other = embeddableBook('second-book-1910', indexable: 2, excluded: 0);

    $this->artisan('books:embed', ['--book' => ['first-book-1900']])->assertSuccessful();

    expect(BookChunk::query()->whereNotNull('embedding')->count())->toBe(2)
        ->and($other->chunks()->whereNotNull('embedding')->count())->toBe(0);
});

it('calls nothing on a dry run', function (): void {
    fakeEmbeddings();
    embeddableBook(indexable: 3, excluded: 0);

    $this->artisan('books:embed', ['--dry-run' => true])
        ->expectsOutputToContain('nothing was written')
        ->assertSuccessful();

    Embeddings::assertNothingGenerated();
    expect(BookChunk::query()->whereNotNull('embedding')->count())->toBe(0);
});

it('fails when no book has chunks', function (): void {
    $this->artisan('books:embed', ['--book' => ['nothing-here']])
        ->expectsOutputToContain('No matching books')
        ->assertFailed();
});

/**
 * With caching off nothing in the package checks the count, and a short batch
 * would attach every vector to its neighbour -- a corpus that looks fine and
 * retrieves the wrong passage for every query.
 */
it('refuses to write a batch whose vector count does not match its inputs', function (): void {
    embeddableBook(indexable: 3, excluded: 0);

    Embeddings::fake(fn ($prompt): array => [Embeddings::fakeEmbedding(1024)]);

    $this->artisan('books:embed')
        ->expectsOutputToContain('refusing to write a mis-indexed batch')
        ->assertFailed();

    expect(BookChunk::query()->whereNotNull('embedding')->count())->toBe(0);
});

it('verifies a sound corpus', function (): void {
    fakeEmbeddings();
    embeddableBook(indexable: 3, excluded: 1);

    $this->artisan('books:embed')->assertSuccessful();

    $this->artisan('books:embed', ['--verify' => true])
        ->expectsOutputToContain('every invariant holds')
        ->assertSuccessful();
});

it('catches an unembedded indexable chunk', function (): void {
    fakeEmbeddings();
    $book = embeddableBook(indexable: 2, excluded: 0);
    $this->artisan('books:embed')->assertSuccessful();

    BookChunk::query()->whereKey($book->chunks()->first()->id)->update(['embedding' => null]);

    $this->artisan('books:embed', ['--verify' => true])
        ->expectsOutputToContain('have no embedding')
        ->assertFailed();
});

it('catches an embedded chunk that should not be retrievable', function (): void {
    fakeEmbeddings();
    $book = embeddableBook(indexable: 1, excluded: 1);
    $this->artisan('books:embed')->assertSuccessful();

    $excluded = $book->chunks()->where('is_indexable', false)->sole();
    DB::update('update book_chunks set embedding = ? where id = ?', [
        '['.implode(',', unitVector(2)).']', $excluded->id,
    ]);

    $this->artisan('books:embed', ['--verify' => true])
        ->expectsOutputToContain('non-indexable chunk(s) carry an embedding')
        ->assertFailed();
});

it('catches a zero-norm vector', function (): void {
    fakeEmbeddings();
    $book = embeddableBook(indexable: 2, excluded: 0);
    $this->artisan('books:embed')->assertSuccessful();

    DB::update('update book_chunks set embedding = ? where id = ?', [
        '['.implode(',', array_fill(0, 1024, 0)).']', $book->chunks()->first()->id,
    ]);

    $this->artisan('books:embed', ['--verify' => true])
        ->expectsOutputToContain('zero-norm vector')
        ->assertFailed();
});

/**
 * books:renormalize and books:chunk both move chunk text without touching any
 * version number, so this is the check that notices.
 */
it('catches a chunk modified after it was embedded', function (): void {
    fakeEmbeddings();
    $book = embeddableBook(indexable: 2, excluded: 0);
    $this->artisan('books:embed')->assertSuccessful();

    DB::update("update book_chunks set updated_at = embedded_at + interval '1 hour' where id = ?", [
        $book->chunks()->first()->id,
    ]);

    $this->artisan('books:embed', ['--verify' => true])
        ->expectsOutputToContain('modified after they were embedded')
        ->assertFailed();
});

it('catches a corpus embedded by two different models', function (): void {
    fakeEmbeddings();
    $book = embeddableBook(indexable: 2, excluded: 0);
    $this->artisan('books:embed')->assertSuccessful();

    DB::update('update book_chunks set embedding_model = ? where id = ?', [
        'nomic-embed-text', $book->chunks()->first()->id,
    ]);

    $this->artisan('books:embed', ['--verify' => true])
        ->expectsOutputToContain('different (model, dimensions, version) triples')
        ->assertFailed();
});

it('catches a corpus embedded by a model configuration no longer asks for', function (): void {
    fakeEmbeddings();
    embeddableBook(indexable: 2, excluded: 0);
    $this->artisan('books:embed')->assertSuccessful();

    config(['books.embedding.model' => 'BAAI/bge-m3-v2']);

    $this->artisan('books:embed', ['--verify' => true])
        ->expectsOutputToContain('but configuration asks for')
        ->assertFailed();
});

/**
 * A second run would embed the same chunks twice and pay hours for it.
 */
it('turns away a second concurrent run', function (): void {
    fakeEmbeddings();
    embeddableBook(indexable: 2, excluded: 0);

    $lock = Cache::lock('books:embed', 60);
    $lock->get();

    try {
        $this->artisan('books:embed')
            ->expectsOutputToContain('already in progress')
            ->assertFailed();
    } finally {
        $lock->release();
    }
});

/**
 * A run killed outright never releases the lock, and the TTL has to outlive a
 * nine-hour corpus run -- so without this hint a killed run blocks every retry
 * for the rest of the day with nothing to say why.
 */
it('says how to clear a lock left by a killed run', function (): void {
    $lock = Cache::lock('books:embed', 60);
    $lock->get();

    try {
        $this->artisan('books:embed')
            ->expectsOutputToContain('forceRelease')
            ->assertFailed();
    } finally {
        $lock->release();
    }
});

/**
 * --verify must not take the lock: the whole point of running it is to inspect
 * a corpus, including while a long run is still going.
 */
it('verifies while a run holds the lock', function (): void {
    fakeEmbeddings();
    embeddableBook(indexable: 2, excluded: 0);
    $this->artisan('books:embed')->assertSuccessful();

    $lock = Cache::lock('books:embed', 60);
    $lock->get();

    try {
        $this->artisan('books:embed', ['--verify' => true])
            ->expectsOutputToContain('every invariant holds')
            ->assertSuccessful();
    } finally {
        $lock->release();
    }
});
