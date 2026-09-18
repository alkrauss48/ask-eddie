<?php

use App\Enums\HouseSourceType;
use App\Models\HouseChunk;

/**
 * The payload half of the house corpus's one-way doors.
 *
 * HouseChunk::toArray() is what a language model is handed at answer time, so
 * this test is about the prompt rather than about presentation. It is asserted
 * by *count* for the same reason BookChunkCitationTest is: a `toContain` would
 * pass forever while a column added to house_chunks next month quietly joined
 * the context. Do not weaken it.
 */
it('hands the model five keys and no bookkeeping', function (): void {
    $chunk = HouseChunk::factory()->embedded(unitVector(1))->create([
        'source_type' => HouseSourceType::Cocktail,
        'source_slug' => 'midnight-rambler',
        'title' => 'Midnight Rambler',
        'subtitle' => 'Our own house original',
        'text' => "Midnight Rambler\n\n2oz Rye Whiskey\n.5oz Blackberry Syrup",
        'keywords' => ['Rye Whiskey', 'Whiskey', 'Fruity'],
        'url' => 'https://thekrausshaus.com/cocktails/midnight-rambler',
    ]);

    $payload = $chunk->toArray();

    expect($payload)->toHaveCount(5)
        ->and(array_keys($payload))->toEqualCanonicalizing(['kind', 'title', 'text', 'url', 'citation']);
});

/**
 * Every one of these is either fusion bookkeeping, an index artefact or a
 * second spelling of something already in the payload. Each reads to a language
 * model as content it may repeat, and "relevance 0.87" in a bartender's answer
 * is both meaningless to a guest and a break in character.
 */
it('keeps the vector, the hashes and the keyword index out of the payload', function (): void {
    $payload = HouseChunk::factory()->embedded(unitVector(1))->create()->toArray();

    foreach ([
        'id', 'source_type', 'source_slug', 'source_id', 'subtitle', 'keywords',
        'content_hash', 'renderer_version', 'char_count', 'word_count', 'token_estimate',
        'is_indexable', 'embedding', 'embedding_model', 'embedding_dimensions',
        'embedder_version', 'embedded_at', 'embedded_content_hash', 'search_vector',
        'created_at', 'updated_at',
    ] as $key) {
        expect($payload)->not->toHaveKey($key);
    }
});

/**
 * A house citation is a link rather than a page range, so the URL belongs in
 * the sentence rather than only in its own field -- a model quoting the
 * citation should not have to think to quote the link as well.
 */
it('renders a citation a guest can open', function (): void {
    $chunk = HouseChunk::factory()->make([
        'title' => 'Mai Tai',
        'subtitle' => null,
        'url' => 'https://thekrausshaus.com/cocktails/mai-tai',
    ]);

    expect($chunk->citation)->toBe('Mai Tai — https://thekrausshaus.com/cocktails/mai-tai');
});

it('carries a subtitle into the citation when there is one', function (): void {
    $chunk = HouseChunk::factory()->make([
        'title' => 'Spring Menu',
        'subtitle' => 'What the house is pouring now',
        'url' => 'https://thekrausshaus.com/menu/spring',
    ]);

    expect($chunk->citation)
        ->toBe('Spring Menu (What the house is pouring now) — https://thekrausshaus.com/menu/spring');
});

/**
 * The kind reaches the model as a word rather than as an enum value, because
 * the payload is prose a bartender reads, not a row.
 */
it('names the kind of record in words', function (): void {
    $payload = HouseChunk::factory()->ofType(HouseSourceType::Path)->create()->toArray();

    expect($payload['kind'])->toBe('Flight');
});

/**
 * The provenance prefix repeats the title, once in the prefix and once at the
 * head of the body. That is the same call BookChunk makes and for the same
 * reason: on a site that is mostly drink names, the name is the most searchable
 * thing on the page.
 */
it('prefixes the embedded string with the title and the kind', function (): void {
    $chunk = HouseChunk::factory()->make([
        'source_type' => HouseSourceType::Cocktail,
        'title' => 'Mai Tai',
        'subtitle' => null,
        'text' => "Mai Tai\n\n1oz Jamaican Rum",
    ]);

    expect($chunk->embeddingText())->toBe("Mai Tai — Cocktail\n\nMai Tai\n\n1oz Jamaican Rum");
});

/**
 * The hash covers the prefix, which is what makes a rename detectable.
 *
 * A cocktail renamed and nothing else has a body that never moved, so a hash
 * over "text" alone would report it as current and leave a vector describing a
 * drink by a name the house no longer uses.
 */
it('changes the content hash when only the title moves', function (): void {
    $body = "2oz Rye Whiskey\n.5oz Blackberry Syrup\nStirred, with no ice.";

    $before = HouseChunk::factory()->make(['title' => 'Midnight Rambler', 'text' => $body])->currentContentHash();
    $after = HouseChunk::factory()->make(['title' => 'The Rambler', 'text' => $body])->currentContentHash();

    expect($before)->not->toBe($after);
});
