<?php

use App\Enums\PageStatus;
use App\Enums\PageTextSource;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\BookPageExtraction;
use App\Services\Books\PagePromoter;

beforeEach(function (): void {
    $this->promoter = new PagePromoter;
    $this->book = Book::factory()->create();
    $this->page = BookPage::factory()->for($this->book)->pending()->create(['page_number' => 1]);
});

function extraction(BookPage $page, PageTextSource $source, ?string $text, ?float $score, PageStatus $status = PageStatus::Extracted): BookPageExtraction
{
    return BookPageExtraction::factory()->for($page, 'page')->create([
        'text_source' => $source,
        'text' => $text,
        'quality_score' => $score,
        'status' => $status,
    ]);
}

it('promotes the highest scoring candidate', function (): void {
    extraction($this->page, PageTextSource::TextLayer, 'The mangled reading.', 0.71);
    $winner = extraction($this->page, PageTextSource::Ocr, 'The clean reading.', 0.96);

    $this->promoter->promote($this->page->fresh(), $this->book);

    $page = $this->page->fresh();

    expect($page->text)->toBe('The clean reading.')
        ->and($page->text_source)->toBe(PageTextSource::Ocr)
        ->and($page->promoted_extraction_id)->toBe($winner->id)
        ->and($page->status)->toBe(PageStatus::Extracted);
});

it('respects the book\'s preferred source over the score', function (): void {
    $preferred = extraction($this->page, PageTextSource::TextLayer, 'The original reading.', 0.71);
    extraction($this->page, PageTextSource::Ocr, 'The rescanned reading.', 0.96);

    $this->book->update(['preferred_text_source' => PageTextSource::TextLayer]);

    $this->promoter->promote($this->page->fresh(), $this->book->fresh());

    expect($this->page->fresh()->promoted_extraction_id)->toBe($preferred->id);
});

/**
 * Pages genuinely are blank sometimes, but so is a failed render. Promoting an
 * empty result would mark the page done and bury the failure.
 */
it('never promotes a blank candidate as the page text', function (?string $text): void {
    extraction($this->page, PageTextSource::Ocr, $text, 0.99);

    $this->promoter->promote($this->page->fresh(), $this->book);

    expect($this->page->fresh()->text)->toBeNull()
        ->and($this->page->fresh()->promoted_extraction_id)->toBeNull()
        ->and($this->page->fresh()->status)->toBe(PageStatus::Blank);
})->with([null, '', '   ', "\n\n"]);

it('ignores candidates that failed', function (): void {
    extraction($this->page, PageTextSource::Ocr, 'Half a reading', 0.99, PageStatus::Failed);
    $winner = extraction($this->page, PageTextSource::TextLayer, 'The only usable reading.', 0.60);

    $this->promoter->promote($this->page->fresh(), $this->book);

    expect($this->page->fresh()->promoted_extraction_id)->toBe($winner->id);
});

it('prefers a scored candidate over an unscored one', function (): void {
    extraction($this->page, PageTextSource::TextLayer, 'Gin.', null);
    $winner = extraction($this->page, PageTextSource::Ocr, 'A full page of readable text.', 0.80);

    $this->promoter->promote($this->page->fresh(), $this->book);

    expect($this->page->fresh()->promoted_extraction_id)->toBe($winner->id);
});

it('recomputes the character and word counts of the promoted text', function (): void {
    extraction($this->page, PageTextSource::Ocr, 'One jigger of rum.', 0.95);

    $this->promoter->promote($this->page->fresh(), $this->book);

    expect($this->page->fresh()->word_count)->toBe(4)
        ->and($this->page->fresh()->char_count)->toBe(18);
});

it('promotes every page of a book at once', function (): void {
    $pages = BookPage::factory()->count(3)->for($this->book)->pending()
        ->sequence(['page_number' => 10], ['page_number' => 11], ['page_number' => 12])
        ->create();

    foreach ($pages as $page) {
        extraction($page, PageTextSource::Ocr, "Text for page {$page->page_number}.", 0.9);
    }

    $promoted = $this->promoter->promoteBook($this->book);

    expect($promoted)->toBe(3)
        ->and($this->book->pages()->where('status', PageStatus::Extracted)->count())->toBe(3);
});

/**
 * Blank leaves and plate versos are ordinary in these scans -- one book has one
 * every sixteenth page. Reporting them as failures would bury real failures.
 */
it('marks a page blank when every candidate ran and came back empty', function (): void {
    extraction($this->page, PageTextSource::TextLayer, '', 0.0);
    extraction($this->page, PageTextSource::Ocr, '   ', 0.0);

    $changed = $this->promoter->promote($this->page->fresh(), $this->book);

    expect($changed)->toBeTrue()
        ->and($this->page->fresh()->status)->toBe(PageStatus::Blank)
        ->and($this->page->fresh()->text)->toBeNull();
});

it('leaves a page failed while any candidate is still failing', function (): void {
    extraction($this->page, PageTextSource::TextLayer, '', 0.0);
    extraction($this->page, PageTextSource::Ocr, null, null, PageStatus::Failed);

    $this->page->forceFill(['status' => PageStatus::Failed])->save();

    $this->promoter->promote($this->page->fresh(), $this->book);

    expect($this->page->fresh()->status)->toBe(PageStatus::Failed);
});

it('does not mark a page blank before anything has been extracted', function (): void {
    $changed = $this->promoter->promote($this->page->fresh(), $this->book);

    expect($changed)->toBeFalse()
        ->and($this->page->fresh()->status)->toBe(PageStatus::Pending);
});

it('recovers a page from blank when a later run finds text', function (): void {
    $this->page->forceFill(['status' => PageStatus::Blank])->save();

    extraction($this->page, PageTextSource::Ocr, 'Text found on a second attempt.', 0.9);

    $this->promoter->promote($this->page->fresh(), $this->book);

    expect($this->page->fresh()->status)->toBe(PageStatus::Extracted)
        ->and($this->page->fresh()->text)->toBe('Text found on a second attempt.');
});
