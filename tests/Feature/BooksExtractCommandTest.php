<?php

use App\Enums\BookStatus;
use App\Enums\PageStatus;
use App\Enums\PageTextSource;
use App\Models\Book;
use App\Models\BookPage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->booksPath = sys_get_temp_dir().'/ask-eddie-extract-'.bin2hex(random_bytes(6));
    $this->tempPath = sys_get_temp_dir().'/ask-eddie-work-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($this->booksPath);

    config([
        'books.disk' => 'books',
        'books.temp_path' => $this->tempPath,
        'books.strategy' => 'ocr_all',
        'books.extraction.concurrency' => 2,
        'filesystems.disks.books' => ['driver' => 'local', 'root' => $this->booksPath],
    ]);

    File::put($this->booksPath.'/book.pdf', 'pdf-bytes');

    $this->book = Book::factory()->create([
        'slug' => 'test-book-1900',
        'source_filename' => 'book.pdf',
        'page_count' => 3,
        'language' => 'eng',
        'status' => BookStatus::Pending,
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->booksPath);
    File::deleteDirectory($this->tempPath);
});

/**
 * A stand-in for pdftoppm that actually writes a file where the real binary
 * would, so that the rendering step behaves as it does in production.
 */
function fakePageRenderer(): Closure
{
    return function ($process) {
        $command = (array) $process->command;
        $prefix = end($command);

        File::ensureDirectoryExists(dirname($prefix));
        File::put($prefix.'-01.png', 'png-bytes');

        return Process::result('');
    };
}

/**
 * Stub the whole toolchain: pdftotext emits form-feed separated pages,
 * pdftoppm writes an image, and tesseract reads one.
 */
function fakeToolchain(string $textLayer, string $ocrText = 'Clean OCR text for this page and more.'): void
{
    Process::fake([
        '*pdftotext*' => $textLayer,
        '*pdftoppm*' => fakePageRenderer(),
        '*tesseract*' => $ocrText,
    ]);
}

it('records both candidates for every page', function (): void {
    fakeToolchain(
        "Layer page one\fLayer page two\fLayer page three\f",
        'Clean OCR text for this page and plenty more besides.'
    );

    $this->artisan('books:extract', ['--book' => ['test-book-1900']])->assertSuccessful();

    expect($this->book->pages()->count())->toBe(3);

    $page = $this->book->pages()->where('page_number', 1)->first();

    expect($page->extractions()->count())->toBe(2)
        ->and($page->extractionFrom(PageTextSource::TextLayer)->text)->toBe('Layer page one');
});

it('promotes the higher scoring candidate', function (): void {
    // A ruined text layer against clean OCR.
    Process::fake([
        '*pdftotext*' => "KOCllESTKli rUKCll xxKKll yyPPmm zzQQnn aaBBcc ddEEff ggHHii\f",
        '*pdftoppm*' => fakePageRenderer(),
        '*tesseract*' => 'Mix thoroughly and strain into a chilled cocktail glass at once.',
    ]);

    $this->book->update(['page_count' => 1]);

    $this->artisan('books:extract', ['--book' => ['test-book-1900']])->assertSuccessful();

    $page = $this->book->pages()->sole();

    expect($page->text_source)->toBe(PageTextSource::Ocr)
        ->and($page->text)->toContain('Mix thoroughly')
        ->and($page->status)->toBe(PageStatus::Extracted)
        ->and($page->promoted_extraction_id)->not->toBeNull();
});

it('honours a book that prefers its text layer', function (): void {
    Process::fake([
        '*pdftotext*' => "A perfectly readable page of the original text layer.\f",
        '*pdftoppm*' => fakePageRenderer(),
        '*tesseract*' => 'A slightly different reading of the very same page here.',
    ]);

    $this->book->update(['page_count' => 1, 'preferred_text_source' => PageTextSource::TextLayer]);

    $this->artisan('books:extract', ['--book' => ['test-book-1900']])->assertSuccessful();

    expect($this->book->pages()->sole()->text_source)->toBe(PageTextSource::TextLayer);
});

it('skips pages that are already done', function (): void {
    fakeToolchain("One\fTwo\fThree\f");

    $this->artisan('books:extract', ['--book' => ['test-book-1900']])->assertSuccessful();

    $before = $this->book->pages()->pluck('updated_at', 'page_number');

    // Everything is extracted, so a second run has nothing to work on.
    $this->artisan('books:extract', ['--book' => ['test-book-1900']])
        ->expectsOutputToContain('Nothing to do')
        ->assertSuccessful();

    $after = $this->book->pages()->pluck('updated_at', 'page_number');

    expect($after->toArray())->toEqual($before->toArray())
        ->and($this->book->pages()->count())->toBe(3);
});

it('re-extracts everything when forced', function (): void {
    fakeToolchain("One\fTwo\fThree\f");

    $this->artisan('books:extract', ['--book' => ['test-book-1900']])->assertSuccessful();
    $this->artisan('books:extract', ['--book' => ['test-book-1900'], '--force' => true])->assertSuccessful();

    Process::assertRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'tesseract'));

    // Forcing must not duplicate rows.
    expect($this->book->pages()->count())->toBe(3)
        ->and($this->book->pages()->first()->extractions()->count())->toBe(2);
});

it('restricts work to a page range', function (): void {
    fakeToolchain("One\fTwo\fThree\f");

    $this->artisan('books:extract', [
        '--book' => ['test-book-1900'],
        '--pages' => '2-3',
    ])->assertSuccessful();

    expect($this->book->pages()->pluck('page_number')->sort()->values()->all())->toBe([2, 3]);
});

it('records an ocr failure but still uses a sound text layer', function (): void {
    Process::fake([
        '*pdftotext*' => "Mix thoroughly and strain into a chilled cocktail glass at once.\f",
        '*pdftoppm*' => fakePageRenderer(),
        '*tesseract*' => Process::result(output: '', errorOutput: 'tesseract exploded', exitCode: 1),
    ]);

    $this->book->update(['page_count' => 1]);

    $this->artisan('books:extract', ['--book' => ['test-book-1900']])->assertSuccessful();

    $page = $this->book->pages()->sole();

    // The OCR attempt is recorded as failed, but the page itself is usable
    // because the other candidate came through.
    expect($page->extractionFrom(PageTextSource::Ocr)->status)->toBe(PageStatus::Failed)
        ->and($page->extractionFrom(PageTextSource::Ocr)->error)->toContain('tesseract exploded')
        ->and($page->status)->toBe(PageStatus::Extracted)
        ->and($page->text_source)->toBe(PageTextSource::TextLayer);
});

it('fails a page only when every candidate fails', function (): void {
    Process::fake([
        '*pdftotext*' => "\f\f\f",
        '*pdftoppm*' => fakePageRenderer(),
        '*tesseract*' => Process::result(output: '', errorOutput: 'tesseract exploded', exitCode: 1),
    ]);

    $this->artisan('books:extract', ['--book' => ['test-book-1900']])->assertSuccessful();

    expect($this->book->pages()->where('status', PageStatus::Failed)->count())->toBe(3)
        ->and($this->book->fresh()->status)->toBe(BookStatus::Failed);
});

it('retries only the pages that failed', function (): void {
    fakeToolchain("One\fTwo\fThree\f");
    $this->artisan('books:extract', ['--book' => ['test-book-1900']])->assertSuccessful();

    BookPage::where('book_id', $this->book->id)
        ->where('page_number', 2)
        ->update(['status' => PageStatus::Failed]);

    Process::fake([
        '*pdftotext*' => "One\fTwo\fThree\f",
        '*pdftoppm*' => fakePageRenderer(),
        '*tesseract*' => 'A repaired reading of the second page of this book.',
    ]);

    $this->artisan('books:extract', [
        '--book' => ['test-book-1900'],
        '--retry-failed' => true,
    ])->assertSuccessful();

    expect($this->book->pages()->where('page_number', 2)->first()->text)
        ->toContain('repaired reading');
});

it('skips ocr under the gated strategy when the text layer is sound', function (): void {
    Process::fake([
        '*pdftotext*' => "Mix thoroughly and strain into a chilled cocktail glass at once please.\f",
        '*tesseract*' => 'should not be needed',
    ]);

    $this->book->update(['page_count' => 1]);

    $this->artisan('books:extract', [
        '--book' => ['test-book-1900'],
        '--strategy' => 'gated',
    ])->assertSuccessful();

    Process::assertNotRan(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'tesseract'));

    expect($this->book->pages()->sole()->text_source)->toBe(PageTextSource::TextLayer);
});

it('falls back to ocr under the gated strategy when the page is blank', function (): void {
    Process::fake([
        '*pdftotext*' => "\f",
        '*pdftoppm*' => fakePageRenderer(),
        '*tesseract*' => 'Text recovered from the page image because there was no layer.',
    ]);

    $this->book->update(['page_count' => 1]);

    $this->artisan('books:extract', [
        '--book' => ['test-book-1900'],
        '--strategy' => 'gated',
    ])->assertSuccessful();

    expect($this->book->pages()->sole()->text_source)->toBe(PageTextSource::Ocr);
});

it('rejects an unknown strategy', function (): void {
    $this->artisan('books:extract', [
        '--book' => ['test-book-1900'],
        '--strategy' => 'guesswork',
    ])->assertFailed();
});

it('fails when no book matches', function (): void {
    $this->artisan('books:extract', ['--book' => ['no-such-book']])->assertFailed();
});

it('refuses to start while another run holds the lock', function (): void {
    fakeToolchain("One\fTwo\fThree\f");

    $lock = Cache::lock('books:extract', 60);
    $lock->get();

    try {
        $this->artisan('books:extract', ['--book' => ['test-book-1900']])
            ->expectsOutputToContain('already in progress')
            ->assertFailed();
    } finally {
        $lock->release();
    }
});

it('releases the lock so a later run can proceed', function (): void {
    fakeToolchain("One\fTwo\fThree\f");

    $this->artisan('books:extract', ['--book' => ['test-book-1900']])->assertSuccessful();
    $this->artisan('books:extract', ['--book' => ['test-book-1900'], '--force' => true])->assertSuccessful();
});

/**
 * A page whose OCR failed still reads as extracted when the text layer came
 * through, but it is running on the weaker candidate and must be retried.
 */
it('retries a page whose ocr failed even though the page itself succeeded', function (): void {
    Process::fake([
        '*pdftotext*' => "Mix thoroughly and strain into a chilled cocktail glass.\f",
        '*pdftoppm*' => fakePageRenderer(),
        '*tesseract*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
    ]);

    $this->book->update(['page_count' => 1]);
    $this->artisan('books:extract', ['--book' => ['test-book-1900']])->assertSuccessful();

    $page = $this->book->pages()->sole();

    expect($page->status)->toBe(PageStatus::Extracted)
        ->and($page->extractionFrom(PageTextSource::Ocr)->status)->toBe(PageStatus::Failed);

    fakeToolchain(
        "Mix thoroughly and strain into a chilled cocktail glass.\f",
        'A clean recovered reading of this page at last.'
    );

    $this->artisan('books:extract', [
        '--book' => ['test-book-1900'],
        '--retry-failed' => true,
    ])->assertSuccessful();

    expect($this->book->pages()->sole()->text)->toContain('clean recovered reading');
});
