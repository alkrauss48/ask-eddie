<?php

use App\Enums\ChunkKind;
use App\Models\Book;
use App\Models\BookChunk;
use App\Models\Drink;
use App\Models\DrinkMention;
use App\Services\Books\DrinkExtractor;
use Illuminate\Support\Facades\Cache;

/**
 * A book with two drinks in one packed chunk, which is the usual shape for the
 * books that are nothing but drink lists.
 */
function bookWithDrinks(array $bookOverrides = []): Book
{
    $book = Book::factory()->create($bookOverrides + [
        'title' => 'Café Royal Cocktail Book',
        'year' => 1937,
        'page_count' => 100,
        'metadata' => ['chunking' => [
            'strategy' => 'headings',
            'chunker_version' => 1,
            'stream_checksum' => 'abc123',
        ]],
    ]);

    $text = "BLUE LADY 1/2 Blue Curaçao.\n1/4 Booth's Gin.\n\nGIN SLING.\n1 wine-glass of gin.";

    BookChunk::factory()->for($book)->create([
        'kind' => ChunkKind::RecipeList,
        'is_indexable' => true,
        'text' => $text,
        'char_count' => mb_strlen($text),
        'char_start' => 0,
        'char_end' => strlen($text),
        'page_from' => 39,
        'page_to' => 39,
    ]);

    return $book;
}

it('tallies the drinks a book prints', function (): void {
    bookWithDrinks();

    $this->artisan('books:drinks')->assertSuccessful();

    expect(Drink::query()->count())->toBe(2)
        ->and(DrinkMention::query()->count())->toBe(2)
        ->and(Drink::query()->pluck('canonical_key')->sort()->values()->all())
        ->toBe(['bluelady', 'ginsling']);
});

/**
 * The test that catches an incremented aggregate. Counts are recomputed from
 * mentions on every run, never added to, because an incremented tally drifts
 * silently across a partial re-run and there is nothing to report it.
 */
it('is idempotent', function (): void {
    bookWithDrinks();

    $this->artisan('books:drinks')->assertSuccessful();
    $this->artisan('books:drinks --force')->assertSuccessful();

    expect(Drink::query()->count())->toBe(2)
        ->and(DrinkMention::query()->count())->toBe(2)
        ->and(Drink::query()->where('canonical_key', 'bluelady')->value('mention_count'))->toBe(1);
});

it('records where each name was printed, and the year it was printed in', function (): void {
    bookWithDrinks();

    $this->artisan('books:drinks')->assertSuccessful();

    $mention = DrinkMention::query()->where('raw_heading', 'BLUE LADY')->firstOrFail();

    expect($mention->page_from)->toBe(39)
        ->and($mention->book_year)->toBe(1937)
        ->and($mention->chunk_kind)->toBe(ChunkKind::RecipeList)
        ->and($mention->char_start)->toBe(0);
});

/**
 * The exclusion that matters more than any other here. A book's own index
 * lists every drink in it exactly once, with a page number pointing somewhere
 * the chunk does not cover; counting it would roughly double every recipe
 * book's tally and attach un-citable pages to it.
 */
it('counts nothing from a chunk retrieval excludes', function (): void {
    $book = Book::factory()->create(['year' => 1937, 'page_count' => 10]);

    BookChunk::factory()->for($book)->create([
        'kind' => ChunkKind::Index,
        'is_indexable' => false,
        'text' => "BLUE LADY 39\nGIN SLING 41\nBRANDY SMASH 44",
    ]);

    $this->artisan('books:drinks')->assertSuccessful();

    expect(DrinkMention::query()->count())->toBe(0)
        ->and(Drink::query()->count())->toBe(0);
});

it('sets aside a division of a book rather than counting it as a drink', function (): void {
    $book = Book::factory()->create(['year' => 1862, 'page_count' => 10]);

    $text = "PUNCHES.\nThese are made as follows, and are all of the old school.";

    BookChunk::factory()->for($book)->create([
        'kind' => ChunkKind::Prose,
        'is_indexable' => true,
        'text' => $text,
        'char_end' => strlen($text),
    ]);

    $this->artisan('books:drinks')->assertSuccessful();

    expect(Drink::query()->count())->toBe(0);
});

it('skips a book already at the current versions and re-extracts it with force', function (): void {
    bookWithDrinks();

    $this->artisan('books:drinks')->assertSuccessful();
    $this->artisan('books:drinks')
        ->expectsOutputToContain('already at extractor version')
        ->assertSuccessful();

    $this->artisan('books:drinks --force')->assertSuccessful();

    expect(DrinkMention::query()->count())->toBe(2);
});

it('writes nothing on a dry run', function (): void {
    bookWithDrinks();

    $this->artisan('books:drinks --dry-run')
        ->expectsOutputToContain('nothing was written')
        ->assertSuccessful();

    expect(Drink::query()->count())->toBe(0)
        ->and(DrinkMention::query()->count())->toBe(0);
});

/**
 * Staleness is read off the chunking state rather than recomputed, so a
 * books:chunk or books:renormalize run makes drinks stale without this layer
 * ever re-assembling a stream.
 */
it('goes stale when the text it was derived from moves', function (): void {
    $book = bookWithDrinks();

    $this->artisan('books:drinks')->assertSuccessful();

    expect(app(DrinkExtractor::class)->isStale($book->fresh()))->toBeFalse();

    $book->forceFill(['metadata' => array_merge($book->metadata, [
        'chunking' => array_merge($book->metadata['chunking'], ['stream_checksum' => 'moved']),
    ])])->save();

    expect(app(DrinkExtractor::class)->isStale($book->fresh()))->toBeTrue();
});

it('turns away a second concurrent run', function (): void {
    bookWithDrinks();

    Cache::lock('books:drinks', 30 * 60)->get();

    $this->artisan('books:drinks')
        ->expectsOutputToContain('already in progress')
        ->assertFailed();
});

it('passes every invariant on a corpus it just built', function (): void {
    bookWithDrinks();

    $this->artisan('books:drinks')->assertSuccessful();
    $this->artisan('books:drinks --verify')
        ->expectsOutputToContain('Every invariant holds')
        ->assertSuccessful();
});

it('names the mention that is not the string it claims to be', function (): void {
    bookWithDrinks();

    $this->artisan('books:drinks')->assertSuccessful();

    DrinkMention::query()->where('raw_heading', 'BLUE LADY')->update(['char_start' => 12]);

    $this->artisan('books:drinks --verify')
        ->expectsOutputToContain('not the string they claim')
        ->assertFailed();
});

it('names a tally its mentions do not support', function (): void {
    bookWithDrinks();

    $this->artisan('books:drinks')->assertSuccessful();

    Drink::query()->where('canonical_key', 'bluelady')->update(['mention_count' => 99]);

    $this->artisan('books:drinks --verify')
        ->expectsOutputToContain('store a tally their mentions do not support')
        ->assertFailed();
});

it('shows one drink and where it was printed', function (): void {
    bookWithDrinks();

    $this->artisan('books:drinks')->assertSuccessful();

    $this->artisan('books:drinks', ['--show' => 'blue lady'])
        ->expectsOutputToContain('Blue Lady')
        ->expectsOutputToContain('Café Royal Cocktail Book (1937)')
        ->assertSuccessful();
});

/**
 * The shape of the tail is what decides whether the tally answers the question
 * it was built for: a corpus where nearly every name is printed once has no
 * "comes up time and time again" to report.
 */
it('reads the head of the tally and the shape of its tail', function (): void {
    Drink::factory()->named('Gin Fizz')->create(['book_count' => 19, 'mention_count' => 34]);
    Drink::factory()->named('Blue Lady')->create(['book_count' => 1, 'mention_count' => 1]);

    $this->artisan('books:drinks --top=5')
        ->expectsOutputToContain('Gin Fizz')
        ->expectsOutputToContain('printed in one book only')
        ->assertSuccessful();
});

it('says so rather than printing an empty table when nothing is tallied', function (): void {
    $this->artisan('books:drinks --top')
        ->expectsOutputToContain('Nothing tallied yet')
        ->assertFailed();
});

/**
 * A drink one book printed is set aside, because that is where this corpus's
 * OCR wreckage lives -- but its mentions stay, which is the whole difference
 * between classifying and deleting.
 */
it('sets aside a name only one book printed without losing its mentions', function (): void {
    bookWithDrinks();

    $this->artisan('books:drinks')->assertSuccessful();

    expect(Drink::query()->where('is_countable', false)->count())->toBe(2)
        ->and(Drink::query()->where('is_countable', true)->count())->toBe(0)
        ->and(DrinkMention::query()->count())->toBe(2);
});

it('counts a name a second book also printed', function (): void {
    bookWithDrinks();
    bookWithDrinks(['title' => 'The Savoy Cocktail Book', 'year' => 1930]);

    $this->artisan('books:drinks')->assertSuccessful();

    expect(Drink::query()->where('is_countable', true)->pluck('canonical_key')->sort()->values()->all())
        ->toBe(['bluelady', 'ginsling']);
});

it('records the measurements behind every verdict', function (): void {
    bookWithDrinks();

    $this->artisan('books:drinks')->assertSuccessful();

    $signals = Drink::query()->where('canonical_key', 'bluelady')->value('signals');

    expect($signals['reason'])->toBe('single_book')
        ->and($signals)->toHaveKeys(['book_count', 'recipe_share', 'heading_families']);
});

/**
 * Editing the noise list must not cost a re-extraction of the whole shelf:
 * classification is a pure function of stored mentions.
 */
it('re-derives countability without re-reading a chunk', function (): void {
    bookWithDrinks();
    bookWithDrinks(['title' => 'The Savoy Cocktail Book', 'year' => 1930]);
    $this->artisan('books:drinks')->assertSuccessful();

    config()->set('books.drinks.classification.noise_headings', ['bluelady']);

    $this->artisan('books:drinks --reclassify')
        ->expectsOutputToContain('1 drink(s) countable')
        ->assertSuccessful();

    expect(Drink::query()->where('canonical_key', 'bluelady')->value('is_countable'))->toBeFalse()
        ->and(Drink::query()->where('canonical_key', 'ginsling')->value('is_countable'))->toBeTrue()
        // Untouched: reclassifying reads mentions and writes drinks.
        ->and(DrinkMention::query()->count())->toBe(4);
});

it('leaves no book reporting itself stale after a reclassify', function (): void {
    bookWithDrinks();
    $this->artisan('books:drinks')->assertSuccessful();
    $this->artisan('books:drinks --reclassify')->assertSuccessful();

    $extractor = app(DrinkExtractor::class);

    expect($extractor->isStale(Book::query()->firstOrFail()))->toBeFalse();
});

it('proposes nothing to review on a corpus with nothing to propose', function (): void {
    bookWithDrinks();
    $this->artisan('books:drinks')->assertSuccessful();

    $this->artisan('books:drinks --noise')
        ->expectsOutputToContain('Nothing reads as a sentence opener')
        ->assertSuccessful();

    $this->artisan('books:drinks --suffixes')
        ->expectsOutputToContain('No "X" / "X Cocktail" pairs')
        ->assertSuccessful();
});

/**
 * Proposed for a human, never merged: "Gin"/"Gin Cocktail" are two drinks and
 * "Manhattan"/"Manhattan Cocktail" are one, and nothing in the strings says
 * which is which.
 */
it('proposes a suffix pair without merging it', function (): void {
    Drink::factory()->named('Manhattan')->create(['book_count' => 10]);
    Drink::factory()->named('Manhattan Cocktail')->create(['book_count' => 20]);

    $this->artisan('books:drinks --suffixes')
        ->expectsOutputToContain('NOT safe to merge')
        ->assertSuccessful();

    expect(Drink::query()->count())->toBe(2);
});
