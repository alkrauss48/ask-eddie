<?php

use App\Models\HouseChunk;
use App\Services\House\HouseEmbedder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

/**
 * Modelled on BooksEmbedCommandTest, and differing in exactly the two places
 * the house corpus differs: staleness is a hash rather than a timestamp
 * comparison, and --verify asserts that the two corpora are still embedded by
 * the same model.
 */
beforeEach(function (): void {
    useHouseFixture();
});

it('embeds every indexable chunk and leaves the rest alone', function (): void {
    fakeEmbeddings();
    importHouse();
    HouseChunk::firstWhere('source_slug', 'the-ramble')->update(['is_indexable' => false]);

    $this->artisan('house:embed')->assertSuccessful();

    expect(HouseChunk::where('is_indexable', true)->whereNotNull('embedding')->count())->toBe(7)
        ->and(HouseChunk::where('is_indexable', false)->whereNotNull('embedding')->count())->toBe(0);
});

it('embeds the provenance-prefixed string rather than the bare text', function (): void {
    fakeEmbeddings();
    importHouse();

    $this->artisan('house:embed')->assertSuccessful();

    Embeddings::assertGenerated(fn ($prompt): bool => (bool) array_filter(
        $prompt->inputs,
        fn (string $input): bool => str_starts_with($input, 'Midnight Rambler — Our own house original — Cocktail'),
    ));
});

it('records the model, dimensions and embedder version on every row', function (): void {
    fakeEmbeddings();
    importHouse();

    $this->artisan('house:embed')->assertSuccessful();

    $chunk = HouseChunk::query()->whereNotNull('embedding')->first();

    expect($chunk->embedding_model)->toBe('BAAI/bge-m3')
        ->and($chunk->embedding_dimensions)->toBe(1024)
        ->and($chunk->embedder_version)->toBe(HouseEmbedder::VERSION)
        ->and($chunk->embedded_at)->not->toBeNull()
        ->and($chunk->embedded_content_hash)->toBe($chunk->content_hash)
        ->and(DB::scalar('select vector_dims(embedding) from house_chunks where id = ?', [$chunk->id]))->toBe(1024);
});

it('honours the batch size', function (): void {
    fakeEmbeddings();
    importHouse();

    $sizes = [];
    Embeddings::fake(function ($prompt) use (&$sizes): array {
        $sizes[] = count($prompt->inputs);

        return array_map(fn (): array => Embeddings::fakeEmbedding(1024), $prompt->inputs);
    });

    $this->artisan('house:embed', ['--batch' => 3])->assertSuccessful();

    expect($sizes)->toBe([3, 3, 2]);
});

it('generates nothing on a second run', function (): void {
    fakeEmbeddings();
    importHouse();
    $this->artisan('house:embed')->assertSuccessful();

    $calls = 0;
    Embeddings::fake(function ($prompt) use (&$calls): array {
        $calls++;

        return array_map(fn (): array => Embeddings::fakeEmbedding(1024), $prompt->inputs);
    });

    $this->artisan('house:embed')
        ->expectsOutputToContain('already carries a current vector')
        ->assertSuccessful();

    expect($calls)->toBe(0);
});

it('re-embeds a current corpus when forced', function (): void {
    fakeEmbeddings();
    importHouse();
    $this->artisan('house:embed')->assertSuccessful();

    $before = HouseChunk::query()->pluck('embedding', 'id')->all();

    fakeEmbeddings();
    $this->artisan('house:embed', ['--force' => true])->assertSuccessful();

    expect(HouseChunk::query()->pluck('embedding', 'id')->all())->not->toBe($before);
});

/**
 * The house's own staleness signal, and the reason it is a stored hash rather
 * than books' embedded_at < updated_at comparison. A re-rendered chunk is
 * *pending*, not broken: the next run picks it up with nobody being told, which
 * is what makes bumping HouseRenderer::VERSION cheap enough to actually do.
 */
it('treats a re-rendered chunk as pending rather than as a failure', function (): void {
    fakeEmbeddings();
    importHouse();
    $this->artisan('house:embed')->assertSuccessful();

    HouseChunk::firstWhere('source_slug', 'midnight-rambler')
        ->update(['content_hash' => hash('sha256', 'a newer render')]);

    expect(app(HouseEmbedder::class)->pending()->count())->toBe(1);

    fakeEmbeddings();
    $this->artisan('house:embed')->assertSuccessful();

    expect(app(HouseEmbedder::class)->pending()->count())->toBe(0);
});

it('makes the corpus pending when the configured model changes', function (): void {
    fakeEmbeddings();
    importHouse();
    $this->artisan('house:embed')->assertSuccessful();

    config()->set('house.embedding.model', 'BAAI/bge-m3-something-else');

    expect(app(HouseEmbedder::class)->pending()->count())->toBe(8);
});

it('reports nothing to embed before anything is imported', function (): void {
    $this->artisan('house:embed')
        ->expectsOutputToContain('Run `house:import` first')
        ->assertFailed();
});

it('calls nothing on a dry run', function (): void {
    importHouse();

    Embeddings::fake(function (): array {
        throw new RuntimeException('The provider must not be called on a dry run.');
    });

    $this->artisan('house:embed', ['--dry-run' => true])
        ->expectsOutputToContain('nothing was written')
        ->assertSuccessful();

    expect(HouseChunk::whereNotNull('embedding')->count())->toBe(0);
});

/**
 * A short batch would attach every vector to its neighbour, producing a corpus
 * that looks entirely fine and retrieves the wrong passage for every query.
 * Nothing in the package checks it, because caching is off.
 */
it('refuses to write a batch the provider returned short', function (): void {
    importHouse();

    Embeddings::fake(fn ($prompt): array => array_map(
        fn (): array => Embeddings::fakeEmbedding(1024),
        array_slice($prompt->inputs, 1),
    ));

    $this->artisan('house:embed')
        ->expectsOutputToContain('refusing to write a mis-indexed batch')
        ->assertFailed();

    expect(HouseChunk::whereNotNull('embedding')->count())->toBe(0);
});

it('refuses to write a vector of the wrong width', function (): void {
    importHouse();

    Embeddings::fake(fn ($prompt): array => array_map(
        fn (): array => Embeddings::fakeEmbedding(512),
        $prompt->inputs,
    ));

    $this->artisan('house:embed')
        ->expectsOutputToContain('but the column holds 1024')
        ->assertFailed();
});

it('verifies a sound corpus', function (): void {
    fakeEmbeddings();
    importHouse();
    $this->artisan('house:embed')->assertSuccessful();

    $this->artisan('house:embed', ['--verify' => true])
        ->expectsOutputToContain('every invariant holds')
        ->assertSuccessful();
});

it('fails verification when an indexable chunk has no vector', function (): void {
    importHouse();

    $this->artisan('house:embed', ['--verify' => true])
        ->expectsOutputToContain('have no embedding')
        ->assertFailed();
});

it('fails verification when a chunk nobody vouched for carries a vector', function (): void {
    fakeEmbeddings();
    importHouse();
    $this->artisan('house:embed')->assertSuccessful();

    HouseChunk::firstWhere('source_slug', 'the-ramble')->update(['is_indexable' => false]);

    $this->artisan('house:embed', ['--verify' => true])
        ->expectsOutputToContain('non-indexable chunk(s) carry an embedding')
        ->assertFailed();
});

/**
 * The failure that cannot be seen in any row: every vector would be the right
 * width, the right count and perfectly self-consistent, and every house query
 * would be embedded by a model that never read this corpus.
 */
it('fails verification when the two corpora drift onto different models', function (): void {
    fakeEmbeddings();
    importHouse();
    $this->artisan('house:embed')->assertSuccessful();

    config()->set('house.embedding.model', 'intfloat/multilingual-e5-large');

    $this->artisan('house:embed', ['--verify' => true])
        // The narrower substring first. Laravel matches these through Mockery,
        // which hands each written line to the first expectation whose argument
        // matcher accepts it -- so a substring that also appears on an earlier
        // line will swallow the line a later expectation was waiting for.
        ->expectsOutputToContain('one server serves one model')
        ->expectsOutputToContain('intfloat/multilingual-e5-large')
        ->assertFailed();
});

it('fails verification when the two corpora drift onto different widths', function (): void {
    config()->set('house.embedding.dimensions', 768);

    $this->artisan('house:embed', ['--verify' => true])
        ->expectsOutputToContain('the house embeds at 768 dimensions but the books embed at 1024')
        ->assertFailed();
});

/**
 * A zero vector has no direction, so cosine distance against it is undefined
 * and pgvector returns NaN -- which poisons the ordering of every query that
 * reaches it.
 */
it('fails verification on a zero-norm vector', function (): void {
    importHouse();

    HouseChunk::firstWhere('source_slug', 'midnight-rambler')
        ->update(['embedding' => array_fill(0, 1024, 0.0), 'embedding_model' => 'BAAI/bge-m3',
            'embedding_dimensions' => 1024, 'embedder_version' => HouseEmbedder::VERSION,
            'embedded_at' => now()]);

    $this->artisan('house:embed', ['--verify' => true])
        ->expectsOutputToContain('zero-norm vector')
        ->assertFailed();
});

it('turns away a second concurrent run and prints the way out', function (): void {
    importHouse();

    $lock = Cache::lock('house:embed', 60);
    $lock->get();

    $this->artisan('house:embed')
        ->expectsOutputToContain('already in progress')
        ->expectsOutputToContain('forceRelease')
        ->assertFailed();

    $lock->release();
});
