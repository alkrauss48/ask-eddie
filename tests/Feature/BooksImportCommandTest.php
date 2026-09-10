<?php

use App\Enums\BookStatus;
use App\Models\Book;
use App\Models\BookPage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->booksPath = sys_get_temp_dir().'/ask-eddie-books-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->booksPath);

    config([
        'books.disk' => 'books',
        'filesystems.disks.books' => ['driver' => 'local', 'root' => $this->booksPath],
    ]);

    Process::fake(['*pdfinfo*' => "Title: Test\nPages:          120\n"]);
});

afterEach(function (): void {
    File::deleteDirectory($this->booksPath);
});

function placePdf(string $path, string $filename, string $contents = 'pdf-bytes'): string
{
    $full = $path.'/'.$filename;
    File::put($full, $contents);

    return $full;
}

it('imports every pdf on the disk', function (): void {
    placePdf($this->booksPath, 'Oxford Night Caps by Richard Cook (1827).pdf');
    placePdf($this->booksPath, '1908 The Worlds Drinks by Hon Wm Boothby.pdf');

    $this->artisan('books:import')->assertSuccessful();

    expect(Book::count())->toBe(2);

    $book = Book::firstWhere('slug', 'oxford-night-caps-1827');

    expect($book->title)->toBe('Oxford Night Caps')
        ->and($book->author)->toBe('Richard Cook')
        ->and($book->year)->toBe(1827)
        ->and($book->page_count)->toBe(120)
        ->and($book->status)->toBe(BookStatus::Pending)
        ->and($book->checksum)->toBe(hash('sha256', 'pdf-bytes'));
});

it('is idempotent and does not re-read an unchanged file', function (): void {
    placePdf($this->booksPath, 'Oxford Night Caps by Richard Cook (1827).pdf');

    $this->artisan('books:import')->assertSuccessful();
    $first = Book::sole();

    $this->artisan('books:import')->assertSuccessful();
    $second = Book::sole();

    expect(Book::count())->toBe(1)
        ->and($second->updated_at->eq($first->updated_at))->toBeTrue();
});

it('discards pages when the pdf content changes', function (): void {
    $path = placePdf($this->booksPath, 'Oxford Night Caps by Richard Cook (1827).pdf');

    $this->artisan('books:import')->assertSuccessful();

    $book = Book::sole();
    BookPage::factory()->count(3)->for($book)->create();

    // A re-scanned PDF may be paginated differently, so pages keyed by the old
    // numbering are misleading rather than merely stale.
    File::put($path, 'different-pdf-bytes');
    touch($path, time() + 10);

    $this->artisan('books:import')->assertSuccessful();

    expect($book->fresh()->pages()->count())->toBe(0)
        ->and($book->fresh()->checksum)->toBe(hash('sha256', 'different-pdf-bytes'))
        ->and($book->fresh()->status)->toBe(BookStatus::Pending);
});

it('marks a vanished book missing without deleting its work', function (): void {
    $path = placePdf($this->booksPath, 'Oxford Night Caps by Richard Cook (1827).pdf');

    $this->artisan('books:import')->assertSuccessful();

    $book = Book::sole();
    BookPage::factory()->count(2)->for($book)->create();

    File::delete($path);
    placePdf($this->booksPath, 'Drinks by Jacques Straub (1914).pdf');

    $this->artisan('books:import')->assertSuccessful();

    expect($book->fresh()->status)->toBe(BookStatus::Missing)
        ->and($book->fresh()->pages()->count())->toBe(2);
});

it('gives three editions of one title distinct slugs', function (): void {
    foreach ([1882, 1888, 1900] as $year) {
        placePdf($this->booksPath, "Harry Johnsons Bartenders Manual ({$year}).pdf", "bytes-{$year}");
    }

    $this->artisan('books:import')->assertSuccessful();

    expect(Book::pluck('slug')->sort()->values()->all())->toBe([
        'harry-johnsons-bartenders-manual-1882',
        'harry-johnsons-bartenders-manual-1888',
        'harry-johnsons-bartenders-manual-1900',
    ]);
});

it('fails when the disk holds no pdfs', function (): void {
    $this->artisan('books:import')->assertFailed();
});

it('writes nothing on a dry run', function (): void {
    placePdf($this->booksPath, 'Oxford Night Caps by Richard Cook (1827).pdf');

    $this->artisan('books:import', ['--dry-run' => true])->assertSuccessful();

    expect(Book::count())->toBe(0);
});

it('refuses a books disk that is not local', function (): void {
    config(['filesystems.disks.books' => ['driver' => 's3', 'bucket' => 'x']]);

    $this->artisan('books:import')->assertFailed();
});
