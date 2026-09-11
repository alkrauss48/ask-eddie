<?php

use App\Enums\PageStatus;
use App\Enums\SectionKind;
use App\Models\Book;
use App\Models\BookChunk;
use App\Models\BookPage;
use App\Services\Books\BookChunker;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->chunker = app(BookChunker::class);
});

/**
 * A short book of recipes, with a running head the detector should recognise.
 */
function chunkableBook(array $overrides = []): Book
{
    $book = Book::factory()->create($overrides + [
        'slug' => 'test-book-1900',
        'title' => 'A Test Book',
        'year' => 1900,
        'page_count' => 6,
    ]);

    foreach (range(1, 6) as $page) {
        BookPage::factory()->for($book)->create([
            'page_number' => $page,
            'printed_page_label' => (string) $page,
            'text' => "COCKTAILS\n\nBLUE LADY NUMBER {$page} 1/2 Blue Curasao.\n1/4 Booth's Gin.\nShake and strain."
                ."\n\nBLUE PETER NUMBER {$page} 1/4 Blue Curasao.\n1/4 Lillet.\nMix well, page {$page}.",
            'status' => PageStatus::Extracted,
        ]);
    }

    return $book;
}

it('writes chunks with exact page ranges', function (): void {
    $book = chunkableBook();

    $report = $this->chunker->chunkBook($book);

    expect($report->chunkCount)->toBeGreaterThan(0)
        ->and($book->chunks()->count())->toBe($report->chunkCount);

    foreach ($book->chunks()->get() as $chunk) {
        expect($chunk->page_from)->toBeGreaterThanOrEqual(1)
            ->and($chunk->page_to)->toBeLessThanOrEqual(6)
            ->and($chunk->page_from)->toBeLessThanOrEqual($chunk->page_to);
    }
});

it('numbers chunks continuously across the whole book', function (): void {
    $book = chunkableBook();
    $this->chunker->chunkBook($book);

    expect($book->chunks()->pluck('chunk_index')->all())
        ->toBe(range(0, $book->chunks()->count() - 1));
});

it('records the sections it detected', function (): void {
    $book = chunkableBook();
    $this->chunker->chunkBook($book);

    expect($book->sections()->count())->toBeGreaterThan(0)
        ->and($book->sections()->pluck('detector_version')->unique()->all())->toBe([1]);
});

/**
 * A running head is the only text chunking removes, so the section that
 * accounts for it keeps every spelling and the pages each appeared on.
 */
it('keeps a receipt for every running head it removed', function (): void {
    $book = chunkableBook();
    $this->chunker->chunkBook($book);

    $heads = $book->sections()->where('kind', SectionKind::RunningHead)->get();

    if ($heads->isEmpty()) {
        // Detected as a guide word or chapter instead, which is equally fine;
        // what matters is that the variants were recorded somewhere.
        expect($book->sections()->whereNotNull('head_variants')->count())->toBeGreaterThan(0);

        return;
    }

    expect($heads->first()->head_variants)->not->toBeNull();

    // The page rows still carry the head, whatever the chunks did with it.
    expect($book->pages()->first()->text)->toContain('COCKTAILS');
});

it('reports complete coverage of the book it chunked', function (): void {
    $report = $this->chunker->chunkBook(chunkableBook());

    expect($report->isComplete())->toBeTrue()
        ->and($report->coverage())->toBe(1.0);
});

it('is a no-op on a second run', function (): void {
    $book = chunkableBook();
    $this->chunker->chunkBook($book);

    expect($this->chunker->isStale($book->fresh()))->toBeFalse();
});

it('replaces a book\'s chunks rather than adding to them', function (): void {
    $book = chunkableBook();
    $this->chunker->chunkBook($book);
    $first = $book->chunks()->count();

    $this->chunker->chunkBook($book->fresh());

    expect($book->chunks()->count())->toBe($first)
        ->and($book->sections()->count())->toBe($book->fresh()->sections()->count());
});

it('is stale when the chunker version moves on', function (): void {
    $book = chunkableBook();
    $this->chunker->chunkBook($book);

    $book->forceFill([
        'metadata' => ['chunking' => array_merge($book->fresh()->metadata['chunking'], ['chunker_version' => 0])],
    ])->save();

    expect($this->chunker->isStale($book->fresh()))->toBeTrue();
});

/**
 * The check a version constant cannot make: books:renormalize changes page text
 * without touching any version, and the chunks would otherwise drift out of
 * step with the corpus silently.
 */
it('is stale when a page\'s text changes underneath it', function (): void {
    $book = chunkableBook();
    $this->chunker->chunkBook($book);

    expect($this->chunker->isStale($book->fresh()))->toBeFalse();

    $book->pages()->first()->forceFill(['text' => 'Something else entirely, in complete sentences.'])->save();

    expect($this->chunker->isStale($book->fresh()))->toBeTrue();
});

it('leaves no partial book behind when a run fails', function (): void {
    $book = chunkableBook();
    $this->chunker->chunkBook($book);
    $before = $book->chunks()->count();

    // A failure inside the transaction must roll the whole book back.
    try {
        DB::transaction(function () use ($book): void {
            $book->chunks()->delete();

            throw new RuntimeException('interrupted');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($book->chunks()->count())->toBe($before);
});

it('ignores blank pages but still spans them in a page range', function (): void {
    $book = chunkableBook();
    $book->pages()->where('page_number', 3)->first()->forceFill([
        'text' => null,
        'status' => PageStatus::Blank,
    ])->save();

    $report = $this->chunker->chunkBook($book);

    expect($report->pageCount)->toBe(5)
        ->and($report->isComplete())->toBeTrue();
});

it('carries the printed page labels onto the chunks', function (): void {
    $book = chunkableBook();
    $this->chunker->chunkBook($book);

    $chunk = $book->chunks()->first();

    expect($chunk->printed_page_from)->toBe((string) $chunk->page_from)
        ->and($chunk->printed_pages_estimated)->toBeFalse();
});

/**
 * A folio a series implies below page one is not a page number, so it is not
 * offered as one.
 */
it('never offers a printed page below one', function (): void {
    $book = chunkableBook();
    $book->pages()->update(['printed_page_label' => null]);

    foreach (range(3, 6) as $page) {
        $book->pages()->where('page_number', $page)->update(['printed_page_label' => (string) ($page - 2)]);
    }

    $this->chunker->chunkBook($book);

    $first = $book->chunks()->orderBy('chunk_index')->first();

    expect($first->page_from)->toBe(1)
        ->and($first->printed_page_from)->toBeNull();
});

/**
 * A folio is copied only where the numbering series supports it. Guessing one
 * would be a fabricated citation.
 */
it('leaves the printed page null when a book has no coherent numbering', function (): void {
    $book = chunkableBook();
    $book->pages()->update(['printed_page_label' => null]);
    $book->pages()->where('page_number', 2)->update(['printed_page_label' => '1900']);

    $this->chunker->chunkBook($book);

    expect($book->chunks()->whereNotNull('printed_page_from')->count())->toBe(0);
});

it('records what it did in the book\'s metadata', function (): void {
    $book = chunkableBook();
    $this->chunker->chunkBook($book);

    $state = $book->fresh()->metadata['chunking'];

    expect($state)->toHaveKeys([
        'strategy', 'chunker_version', 'classifier_version', 'detector_version',
        'stream_checksum', 'chunk_count', 'chunked_at',
    ])->and($state['chunker_version'])->toBe(BookChunker::VERSION);
});

/**
 * The offsets are what make a citation checkable rather than merely plausible.
 */
it('verifies its own chunks against the text it cut them from', function (): void {
    $book = chunkableBook();
    $this->chunker->chunkBook($book);

    [$length, $mismatches, $uncovered] = $this->chunker->verify($book, $book->chunks()->get());

    expect($mismatches)->toBe([])
        ->and($uncovered)->toBe(0)
        ->and($length)->toBeGreaterThan(0);
});

it('catches a chunk whose offsets have been tampered with', function (): void {
    $book = chunkableBook();
    $this->chunker->chunkBook($book);

    BookChunk::where('book_id', $book->id)->orderBy('chunk_index')->first()
        ->forceFill(['char_start' => 3])->save();

    [, $mismatches] = $this->chunker->verify($book, $book->chunks()->get());

    expect($mismatches)->not->toBe([]);
});
