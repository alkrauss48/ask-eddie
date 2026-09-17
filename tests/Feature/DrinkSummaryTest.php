<?php

use App\Models\Drink;
use App\Services\Retrieval\DrinkQuery;
use Illuminate\Support\Facades\DB;

/**
 * The payload contract, at the boundary it crosses. The reasoning is the one
 * .ai/rules/retrieval.md gives for the eight-key passage payload: anything in
 * here is read by the model as content it may repeat, and a guest has no use
 * for a folded key.
 */
it('hands the model exactly six keys', function (): void {
    tallied();

    $payload = surveyor()->survey(new DrinkQuery)->first()->payload();

    expect(array_keys($payload))->toEqualCanonicalizing([
        'name', 'books', 'mentions', 'years', 'also_printed_as', 'citations',
    ])->and($payload)->toHaveCount(6);
});

it('leaks no id, slug, key or score', function (): void {
    tallied();

    $json = strtolower(json_encode(surveyor()->survey(new DrinkQuery)->first()->payload()));

    foreach (['"id"', 'slug', 'canonical_key', 'score', 'version', 'book_id'] as $leak) {
        expect($json)->not->toContain($leak);
    }
});

/**
 * Rendered from the mention's own chunk rather than from the columns copied
 * beside it, so the two tools cannot drift apart: a drink cites a passage
 * exactly as the passage would cite itself, tilde and all.
 */
it('cites a drink exactly as its passage would cite itself', function (): void {
    $drink = tallied();
    $mention = $drink->mentions()->firstOrFail();

    $summary = surveyor()->survey(new DrinkQuery)->first();

    expect($summary->citations[0])
        ->toBe($mention->chunk->citation)
        ->toBe('Old Waldorf Bar Days (1931), "Concerning the Curriculum", p. 107 (PDF p. 119)');
});

it('collapses a single year and renders a span', function (): void {
    tallied();

    expect(surveyor()->survey(new DrinkQuery)->first()->years)->toBe('1931');

    Drink::query()->update(['last_year' => 1937]);

    expect(surveyor()->survey(new DrinkQuery)->first()->years)->toBe('1931–1937');
});

it('gives an empty list rather than null when there is one spelling', function (): void {
    tallied();

    $payload = surveyor()->survey(new DrinkQuery)->first()->payload();

    expect($payload['also_printed_as'])->toBe([]);
});

it('names the other spellings, most printed first', function (): void {
    $drink = tallied();

    $drink->forceFill(['aliases' => ['BLUE LADY' => 14, 'BLUE LADV' => 1, 'Blue Lady' => 3]])->save();

    $payload = surveyor()->survey(new DrinkQuery)->first()->payload();

    // The canonical spelling is not one of its own alternatives.
    expect($payload['also_printed_as'])->toBe(['BLUE LADY', 'BLUE LADV']);
});

/**
 * A survey of ubiquitous drinks would otherwise cost a query per citation, and
 * the chunk it renders from carries a 4 KB vector it never reads.
 */
it('costs a bounded number of queries however many drinks come back', function (): void {
    for ($index = 0; $index < 5; $index++) {
        tallied(['canonical_key' => "drink{$index}", 'slug' => "drink-{$index}"], books: 2);
    }

    DB::enableQueryLog();

    surveyor()->survey(new DrinkQuery(limit: 25));

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(5);

    DB::disableQueryLog();
});
