<?php

use App\Enums\ChunkStrategy;
use App\Enums\PageStatus;
use App\Models\Book;
use App\Models\BookChunk;
use App\Models\BookPage;
use App\Services\Books\BookChunker;
use App\Services\Books\BookTextStream;
use App\Services\Books\ChunkPacker;
use App\Services\Books\DetectedStructure;
use App\Services\Books\TokenEstimator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

function commandBook(string $slug = 'test-book-1900', int $year = 1900): Book
{
    $book = Book::factory()->create([
        'slug' => $slug,
        'title' => 'A Test Book',
        'year' => $year,
        'page_count' => 4,
    ]);

    foreach (range(1, 4) as $page) {
        BookPage::factory()->for($book)->create([
            'page_number' => $page,
            'printed_page_label' => (string) $page,
            'text' => "BLUE LADY NUMBER {$page} 1/2 Blue Curasao.\n1/4 Booth's Gin.\nShake and strain."
                ."\n\nBLUE PETER NUMBER {$page} 1/4 Blue Curasao.\nMix well, page {$page}.",
            'status' => PageStatus::Extracted,
        ]);
    }

    return $book;
}

it('chunks a named book', function (): void {
    $book = commandBook();

    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])
        ->expectsOutputToContain('no content dropped')
        ->assertSuccessful();

    expect($book->chunks()->count())->toBeGreaterThan(0);
});

it('fails when no book matches', function (): void {
    $this->artisan('books:chunk', ['--book' => ['nothing-here']])
        ->expectsOutputToContain('No matching books')
        ->assertFailed();
});

it('skips a book that is already current', function (): void {
    $book = commandBook();
    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])->assertSuccessful();

    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])
        ->expectsOutputToContain('already at chunker version')
        ->assertSuccessful();
});

it('re-chunks a current book when forced', function (): void {
    $book = commandBook();
    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])->assertSuccessful();
    $before = $book->chunks()->pluck('id')->all();

    $this->artisan('books:chunk', ['--book' => ['test-book-1900'], '--force' => true])->assertSuccessful();

    expect($book->chunks()->count())->toBe(count($before))
        // Rebuilt wholesale rather than patched, because boundaries are global
        // to a book.
        ->and($book->chunks()->pluck('id')->all())->not->toBe($before);
});

it('re-chunks a stale book without being forced', function (): void {
    $book = commandBook();
    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])->assertSuccessful();

    $book->forceFill([
        'metadata' => ['chunking' => array_merge($book->fresh()->metadata['chunking'], ['chunker_version' => 0])],
    ])->save();

    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])
        ->expectsOutputToContain('no content dropped')
        ->assertSuccessful();

    expect($book->fresh()->metadata['chunking']['chunker_version'])->toBe(BookChunker::VERSION);
});

it('writes nothing on a dry run', function (): void {
    $book = commandBook();

    $this->artisan('books:chunk', ['--book' => ['test-book-1900'], '--dry-run' => true])
        ->expectsOutputToContain('nothing was written')
        ->assertSuccessful();

    expect($book->chunks()->count())->toBe(0)
        ->and($book->sections()->count())->toBe(0);
});

it('turns away a second concurrent run', function (): void {
    commandBook();

    $lock = Cache::lock('books:chunk', 60);
    $lock->get();

    try {
        $this->artisan('books:chunk')
            ->expectsOutputToContain('already in progress')
            ->assertFailed();
    } finally {
        $lock->release();
    }
});

/**
 * Chunking is a database operation. If it ever starts shelling out, the OCR
 * toolchain's constraints come with it and this should fail loudly.
 */
it('runs no external processes', function (): void {
    Process::fake();
    Process::preventStrayProcesses();

    commandBook();

    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])->assertSuccessful();

    Process::assertNothingRan();
});

it('reports the printed page situation', function (): void {
    commandBook();

    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])
        ->expectsOutputToContain('cite the PDF page only')
        ->assertSuccessful();
});

/**
 * The central promise, as an exit code rather than a line in a table: a run
 * that loses content is a failed run.
 */
it('fails the run when content is dropped', function (): void {
    commandBook();

    // A packer that quietly discards half its work stands in for any future
    // change that loses text.
    $this->app->bind(ChunkPacker::class, fn ($app) => new class($app->make(TokenEstimator::class)) extends ChunkPacker
    {
        public function pack(
            BookTextStream $stream,
            array $blocks,
            ChunkStrategy $strategy,
            DetectedStructure $structure,
        ): array {
            return array_slice(parent::pack($stream, $blocks, $strategy, $structure), 0, 1);
        }
    });

    $this->artisan('books:chunk', ['--book' => ['test-book-1900']])
        ->expectsOutputToContain('content was dropped')
        ->assertFailed();
});

it('chunks every book when none is named', function (): void {
    commandBook('first-book-1900', 1900);
    commandBook('second-book-1910', 1910);

    $this->artisan('books:chunk')->assertSuccessful();

    expect(BookChunk::count())->toBeGreaterThan(0)
        ->and(Book::whereHas('chunks')->count())->toBe(2);
});

it('ignores a book with no extracted pages', function (): void {
    $book = Book::factory()->create(['slug' => 'empty-book-1900', 'page_count' => 2]);
    BookPage::factory()->for($book)->pending()->create(['page_number' => 1]);

    $this->artisan('books:chunk', ['--book' => ['empty-book-1900']])
        ->expectsOutputToContain('No matching books')
        ->assertFailed();
});
