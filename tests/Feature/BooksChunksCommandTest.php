<?php

use App\Enums\PageStatus;
use App\Models\Book;
use App\Models\BookChunk;
use App\Models\BookPage;

function inspectableBook(): Book
{
    $book = Book::factory()->create([
        'slug' => 'test-book-1900',
        'title' => 'A Test Book',
        'year' => 1900,
        'page_count' => 5,
    ]);

    foreach (range(1, 5) as $page) {
        BookPage::factory()->for($book)->create([
            'page_number' => $page,
            'printed_page_label' => (string) $page,
            'text' => "COCKTAILS\n\nBLUE LADY NUMBER {$page} 1/2 Blue Curasao.\n1/4 Booth's Gin.\nShake and strain."
                ."\n\nSangaree, Ale ... . ........ . ... .. 370\nShrub, Rum .. . . .. . ........... . 399\nSling, Cold ..... . ... 401",
            'status' => PageStatus::Extracted,
        ]);
    }

    return $book;
}

it('reports an unknown book', function (): void {
    $this->artisan('books:chunks', ['book' => 'nothing-here'])
        ->expectsOutputToContain('No book with slug')
        ->assertFailed();
});

it('asks for a chunk run before showing an outline', function (): void {
    inspectableBook();

    $this->artisan('books:chunks', ['book' => 'test-book-1900', '--outline' => true])
        ->expectsOutputToContain('books:chunk')
        ->assertFailed();
});

it('shows the detected outline', function (): void {
    inspectableBook();
    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])->assertSuccessful();

    $this->artisan('books:chunks', ['book' => 'test-book-1900', '--outline' => true])
        ->expectsOutputToContain('A Test Book')
        ->assertSuccessful();
});

/**
 * The payoff for storing byte offsets: the citations are proven rather than
 * trusted.
 */
it('verifies every chunk against the text it was cut from', function (): void {
    inspectableBook();
    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])->assertSuccessful();

    $this->artisan('books:chunks', ['book' => 'test-book-1900', '--verify' => true])
        ->expectsOutputToContain('every chunk is exactly the slice it claims')
        ->expectsOutputToContain('every content character is accounted for')
        ->assertSuccessful();
});

it('fails verification when a chunk\'s offsets are tampered with', function (): void {
    $book = inspectableBook();
    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])->assertSuccessful();

    $book->chunks()->orderBy('chunk_index')->first()->forceFill(['char_start' => 7])->save();

    $this->artisan('books:chunks', ['book' => 'test-book-1900', '--verify' => true])
        ->assertFailed();
});

it('fails verification when a chunk claims the wrong page', function (): void {
    $book = inspectableBook();
    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])->assertSuccessful();

    $book->chunks()->orderBy('chunk_index')->first()->forceFill(['page_from' => 4, 'page_to' => 4])->save();

    $this->artisan('books:chunks', ['book' => 'test-book-1900', '--verify' => true])
        ->assertFailed();
});

it('shows only the chunks covering one page', function (): void {
    inspectableBook();
    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])->assertSuccessful();

    $this->artisan('books:chunks', ['book' => 'test-book-1900', '--page' => 3])
        ->assertSuccessful();
});

/**
 * Nothing is deleted for being unindexable, so it can be read back and the
 * judgement re-examined.
 */
it('shows the chunks that will not be embedded', function (): void {
    inspectableBook();
    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])->assertSuccessful();

    expect(BookChunk::where('is_indexable', false)->count())->toBeGreaterThan(0);

    $this->artisan('books:chunks', ['book' => 'test-book-1900', '--excluded' => true])
        ->assertSuccessful();
});

it('prints the retrieval payload', function (): void {
    inspectableBook();
    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])->assertSuccessful();

    // The payload's exact shape is pinned in BookChunkCitationTest; this only
    // proves the command renders it.
    $this->artisan('books:chunks', ['book' => 'test-book-1900', '--payload' => true, '--limit' => 1])
        ->expectsOutputToContain('"citation"')
        ->assertSuccessful();
});

it('filters by kind', function (): void {
    inspectableBook();
    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])->assertSuccessful();

    $this->artisan('books:chunks', ['book' => 'test-book-1900', '--kind' => ['index']])
        ->assertSuccessful();
});

it('reports when nothing matches a filter', function (): void {
    inspectableBook();
    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])->assertSuccessful();

    $this->artisan('books:chunks', ['book' => 'test-book-1900', '--search' => 'zzzzznothing'])
        ->expectsOutputToContain('No chunks matched')
        ->assertFailed();
});
