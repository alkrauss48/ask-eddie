<?php

use App\Models\HouseChunk;
use Illuminate\Support\Facades\DB;

/**
 * The schema half of the house corpus's one-way doors.
 *
 * Smaller than BookChunkEmbeddingSchemaTest by one case and larger by another,
 * and both differences are the point. There is no HNSW index here, so the
 * cosine-operator pin is replaced by a test that asserts the index's *absence*
 * -- otherwise adding one later is something that just happens rather than
 * something somebody decided. The lexical column still needs its
 * NULL-propagation pin, because most of this corpus has no subtitle and a menu
 * has no keywords worth the name.
 */
it('sizes the vector column to the configured dimensions', function (): void {
    $type = DB::scalar(<<<'SQL'
        select format_type(a.atttypid, a.atttypmod)
        from pg_attribute a
        join pg_class c on c.oid = a.attrelid
        where c.relname = 'house_chunks' and a.attname = 'embedding'
    SQL);

    expect($type)->toBe('vector('.config('house.embedding.dimensions').')');
});

/**
 * One TEI container serves one model. If the two corpora are configured to
 * embed with different models, house queries are embedded by a model that never
 * read the house corpus -- with no error, and plausible-looking output.
 * `house:embed --verify` asserts this at runtime; this asserts it at edit time.
 */
it('embeds the house with the same model and width as the books', function (): void {
    expect(config('house.embedding.model'))->toBe(config('books.embedding.model'))
        ->and(config('house.embedding.dimensions'))->toBe(config('books.embedding.dimensions'))
        ->and(config('house.embedding.provider'))->toBe(config('books.embedding.provider'));
});

/**
 * The absence is deliberate, and this is what makes it a decision.
 *
 * At ~207 rows an exact scan is sub-millisecond with 100% recall. pgvector
 * post-filters, so an is_indexable predicate over an approximate scan can
 * quietly return a short list -- and unlike books there is no ef_search knob
 * here to raise, because config/house.php deliberately has none.
 */
it('has no approximate vector index, and no knob that would pretend to tune one', function (): void {
    $indexes = DB::table('pg_indexes')
        ->where('tablename', 'house_chunks')
        ->pluck('indexdef')
        ->implode("\n");

    expect($indexes)->not->toContain('hnsw')
        ->and($indexes)->not->toContain('ivfflat')
        ->and(config('house.retrieval'))->not->toHaveKey('ef_search');
});

it('indexes the search vector with gin', function (): void {
    $definition = DB::scalar(
        "select indexdef from pg_indexes where indexname = 'house_chunks_search_vector_gin'"
    );

    expect($definition)->toContain('gin')
        ->and($definition)->toContain('search_vector');
});

/**
 * The NULL-propagation pin. compileFulltext() emits
 * to_tsvector(cfg, a) || to_tsvector(cfg, b) with no coalesce, and NULL
 * propagates through ||, so with $table->fullText() every chunk lacking a
 * keyword list would index as NULL and never match anything.
 */
it('still indexes a chunk with no keywords', function (): void {
    HouseChunk::factory()->create([
        'title' => 'Spring Menu',
        'keywords' => null,
        'text' => 'Rye, blackberry and a long twist of lemon.',
    ]);

    expect(HouseChunk::query()
        ->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", ['blackberry'])
        ->count())->toBe(1);
});

/**
 * Why the keywords column exists at all: a cocktail that never says "Jamaican
 * rum" in its prose still has to be findable by it, because that is what the
 * catalog knows about the bottle it does name.
 */
it('finds a drink by structured vocabulary its prose never uses', function (): void {
    HouseChunk::factory()->create([
        'title' => 'Mai Tai',
        'keywords' => ['Smith and Cross', 'Jamaican Rum', 'Tiki', 'Higher Alcohol'],
        'text' => "Mai Tai\n\n1oz Smith and Cross\n1oz Lime Juice\nShaken, over crushed ice.",
    ]);

    expect(HouseChunk::query()
        ->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", ['"jamaican rum"'])
        ->count())->toBe(1);
});

/**
 * The weighting decision, made assertable. A tag that says "Tiki" is a stronger
 * claim about a drink than a sentence that happens to mention tiki bars.
 */
it('weights the keyword vocabulary above the body text', function (): void {
    $tagged = HouseChunk::factory()->create([
        'title' => 'Zombie',
        'keywords' => ['Tiki', 'Rum'],
        'text' => 'Three rums, falernum, grapefruit and a dash of grenadine.',
    ]);

    $mentioned = HouseChunk::factory()->create([
        'title' => 'Sidecar',
        'keywords' => ['Brandy'],
        'text' => 'Nothing like the tiki drinks two stools down, but it holds its own.',
    ]);

    $ranked = HouseChunk::query()
        ->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", ['tiki'])
        ->orderByRaw("ts_rank_cd(search_vector, websearch_to_tsquery('english', ?), 32) desc", ['tiki'])
        ->pluck('id')
        ->all();

    expect($ranked)->toBe([$tagged->id, $mentioned->id]);
});

/**
 * Stored, not triggered: the column recomputes because Postgres recomputes it,
 * so nothing in the application can forget to.
 */
it('recomputes the search vector when the text changes', function (): void {
    $chunk = HouseChunk::factory()->create(['text' => 'Nothing relevant here.']);

    $matches = fn (string $term): int => HouseChunk::query()
        ->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", [$term])
        ->count();

    expect($matches('orgeat'))->toBe(0);

    $chunk->update(['text' => 'Half an ounce of orgeat, shaken hard.']);

    expect($matches('orgeat'))->toBe(1);
});

it('round-trips a vector through the cast', function (): void {
    $vector = unitVector(7, (int) config('house.embedding.dimensions'));

    HouseChunk::factory()->embedded($vector)->create();

    expect(HouseChunk::query()->sole()->embedding)->toBe($vector)
        ->and(DB::scalar('select vector_dims(embedding) from house_chunks'))
        ->toBe((int) config('house.embedding.dimensions'));
});

/**
 * One-hot vectors give cosine similarity of exactly 1 or exactly 0, so this
 * asserts the distance operator as well as the cast -- an exact scan still has
 * to be ordered by the right thing.
 */
it('orders by cosine similarity against a stored vector', function (): void {
    $match = HouseChunk::factory()->embedded(unitVector(3))->create();
    HouseChunk::factory()->embedded(unitVector(9))->create();

    expect(HouseChunk::query()
        ->whereVectorSimilarTo('embedding', unitVector(3), 0.99)
        ->pluck('id')
        ->all())->toBe([$match->id]);
});

/**
 * jsonb canonicalizes key order, which would make a cocktail's content_hash
 * unstable across a round trip that changed nothing -- and an unstable hash
 * means every import reports a change and no import can be trusted when it
 * reports one.
 */
it('stores the raw exported record as json rather than jsonb', function (): void {
    $type = DB::scalar(<<<'SQL'
        select format_type(a.atttypid, a.atttypmod)
        from pg_attribute a
        join pg_class c on c.oid = a.attrelid
        where c.relname = 'house_cocktails' and a.attname = 'source'
    SQL);

    expect($type)->toBe('json');
});

/**
 * The retrieval select must not drag 4 KB of vector and a second copy of the
 * text back for every citation.
 */
it('leaves the vector and the search vector out of a retrieval select', function (): void {
    HouseChunk::factory()->embedded(unitVector(1))->create();

    $chunk = HouseChunk::query()->forRetrieval()->sole();

    expect($chunk->getAttributes())->not->toHaveKey('embedding')
        ->and($chunk->getAttributes())->not->toHaveKey('search_vector')
        ->and($chunk->getAttributes())->toHaveKey('text')
        ->and($chunk->embedding_model)->toBe((string) config('house.embedding.model'));
});
