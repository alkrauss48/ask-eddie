<?php

use App\Enums\PageStatus;
use App\Enums\PageTextSource;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\BookPageExtraction;
use App\Services\Books\PageTextNormalizer;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->book = Book::factory()->create(['slug' => 'test-book-1900']);
    $this->page = BookPage::factory()->for($this->book)->pending()->create(['page_number' => 1]);
});

function staleExtraction(BookPage $page, string $rawText): BookPageExtraction
{
    return BookPageExtraction::factory()->for($page, 'page')->create([
        'text_source' => PageTextSource::Ocr,
        'raw_text' => $rawText,
        'text' => 'stale text',
        'quality_score' => 0.1,
        'normalizer_version' => PageTextNormalizer::VERSION - 1,
        'status' => PageStatus::Extracted,
    ]);
}

/**
 * The whole point of retaining raw text is that improving the normalizer costs
 * a pass over the database instead of another run of the OCR toolchain.
 */
it('re-derives text without invoking any external process', function (): void {
    staleExtraction($this->page, 'Mix thoroughly and strain into a chilled cocktail glass.');

    Process::fake();
    Process::preventStrayProcesses();

    $this->artisan('books:renormalize')->assertSuccessful();

    Process::assertNothingRan();

    expect($this->page->fresh()->text)->toBe('Mix thoroughly and strain into a chilled cocktail glass.');
});

it('recovers a printed page label that the previous version missed', function (): void {
    staleExtraction($this->page, "26      ROCHESTER PUNCH.\n\nOne pint of Jamaica rum.");

    $this->artisan('books:renormalize')->assertSuccessful();

    expect($this->page->fresh()->printed_page_label)->toBe('26');
});

it('brings extractions up to the current normalizer version', function (): void {
    $extraction = staleExtraction($this->page, 'One jigger of rum and two dashes of bitters.');

    $this->artisan('books:renormalize')->assertSuccessful();

    expect($extraction->fresh()->normalizer_version)->toBe(PageTextNormalizer::VERSION)
        ->and($extraction->fresh()->quality_score)->toBeGreaterThan(0.5);
});

it('leaves extractions that are already current alone', function (): void {
    BookPageExtraction::factory()->for($this->page, 'page')->create([
        'raw_text' => 'Already normalized.',
        'text' => 'Already normalized.',
        'normalizer_version' => PageTextNormalizer::VERSION,
    ]);

    $this->artisan('books:renormalize')
        ->expectsOutputToContain('already at normalizer version')
        ->assertSuccessful();
});

it('re-normalizes current extractions when asked for all', function (): void {
    $extraction = BookPageExtraction::factory()->for($this->page, 'page')->create([
        'text_source' => PageTextSource::Ocr,
        'raw_text' => 'One   jigger    of rum.',
        'text' => 'not recomputed',
        'normalizer_version' => PageTextNormalizer::VERSION,
        'status' => PageStatus::Extracted,
    ]);

    $this->artisan('books:renormalize', ['--all' => true])->assertSuccessful();

    expect($extraction->fresh()->text)->toBe('One jigger of rum.');
});

it('promotes the re-derived text onto the page', function (): void {
    staleExtraction($this->page, 'The freshly normalized reading of this page.');

    $this->artisan('books:renormalize')->assertSuccessful();

    expect($this->page->fresh()->text)->toBe('The freshly normalized reading of this page.')
        ->and($this->page->fresh()->status)->toBe(PageStatus::Extracted);
});
