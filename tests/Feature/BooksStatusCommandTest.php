<?php

use App\Enums\PageStatus;
use App\Enums\PageTextSource;
use App\Models\Book;
use App\Models\BookPage;
use Illuminate\Support\Facades\Artisan;

it('reports each book with its page tallies and source split', function (): void {
    $book = Book::factory()->create(['slug' => 'oxford-night-caps-1827', 'page_count' => 3]);

    BookPage::factory()->for($book)->create(['page_number' => 1, 'text_source' => PageTextSource::Ocr]);
    BookPage::factory()->for($book)->create(['page_number' => 2, 'text_source' => PageTextSource::TextLayer]);
    BookPage::factory()->for($book)->pending()->create(['page_number' => 3, 'status' => PageStatus::Blank]);

    // Rendered through a table, so the output is asserted as a whole rather
    // than line by line.
    $code = Artisan::call('books:status');
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('oxford-night-caps-1827')
        ->and($output)->toContain('ocr 1 / layer 1')
        ->and($output)->toContain('3 of 3 pages accounted for (100.0%)');
});

/**
 * The pages relation orders by page number, which Postgres rejects alongside
 * the GROUP BY used to tally page states.
 */
it('tallies page states without tripping over the relation ordering', function (): void {
    $book = Book::factory()->create(['page_count' => 4]);

    BookPage::factory()->count(2)->for($book)->sequence(
        ['page_number' => 1], ['page_number' => 2]
    )->create();
    BookPage::factory()->for($book)->pending()->create(['page_number' => 3, 'status' => PageStatus::Blank]);
    BookPage::factory()->for($book)->failed()->create(['page_number' => 4]);

    $this->artisan('books:status')->assertSuccessful();
});

it('can be limited to one book', function (): void {
    Book::factory()->create(['slug' => 'wanted-1900']);
    Book::factory()->create(['slug' => 'unwanted-1901']);

    $this->artisan('books:status', ['--book' => ['wanted-1900']])
        ->expectsOutputToContain('wanted-1900')
        ->doesntExpectOutputToContain('unwanted-1901')
        ->assertSuccessful();
});

it('fails when nothing has been imported', function (): void {
    $this->artisan('books:status')->assertFailed();
});
