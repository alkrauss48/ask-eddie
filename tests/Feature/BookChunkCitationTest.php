<?php

use App\Models\Book;
use App\Models\BookChunk;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The Phase 5 contract.
 *
 * Laravel's SimilaritySearch tool builds its own query and hands the model
 * Arr::except($chunk->toArray(), ['embedding']) for every hit. That makes this
 * model's serialized shape the citation payload itself rather than a
 * presentation detail, which is why it is pinned here.
 */
function citedChunk(array $overrides = []): BookChunk
{
    $book = Book::factory()->create([
        'title' => 'Old Waldorf Bar Days',
        'author' => 'Albert Stevens Crockett',
        'year' => 1931,
    ]);

    return BookChunk::factory()->for($book)->create($overrides + [
        'section_title' => 'Concerning the Curriculum',
        'heading' => null,
        'text' => 'As I have said, their nomenclature deserves to live in history.',
        'page_from' => 119,
        'page_to' => 120,
        'printed_page_from' => '107',
        'printed_page_to' => '108',
    ]);
}

it('exposes exactly the citation payload keys', function (): void {
    $payload = Arr::except(citedChunk()->toArray(), ['embedding']);

    // Asserted exactly, and by count, so that a column added later cannot leak
    // into the prompt unnoticed.
    expect(array_keys($payload))->toEqualCanonicalizing([
        'book_title', 'author', 'year', 'section_title', 'heading', 'pages', 'citation', 'text',
    ])->and($payload)->toHaveCount(8);
});

it('keeps operational columns out of the payload', function (): void {
    $payload = citedChunk()->toArray();

    foreach ([
        'id', 'book_id', 'book_section_id', 'chunk_index', 'kind', 'is_indexable',
        'char_start', 'char_end', 'page_from', 'page_to', 'signals',
        'chunker_version', 'classifier_version', 'book', 'section',
    ] as $hidden) {
        expect($payload)->not->toHaveKey($hidden);
    }
});

it('names the book, its author and its year', function (): void {
    $payload = citedChunk()->toArray();

    expect($payload['book_title'])->toBe('Old Waldorf Bar Days')
        ->and($payload['author'])->toBe('Albert Stevens Crockett')
        ->and($payload['year'])->toBe(1931);
});

it('renders a printed page range alongside the physical one', function (): void {
    expect(citedChunk()->pages)->toBe('pp. 107–108 (PDF pp. 119–120)');
});

it('collapses a single page', function (): void {
    $chunk = citedChunk(['page_from' => 119, 'page_to' => 119, 'printed_page_to' => '107']);

    expect($chunk->pages)->toBe('p. 107 (PDF p. 119)');
});

/**
 * The smallest honest signal available: a printed page that was interpolated
 * from the book's numbering series rather than read off the page.
 */
it('marks an interpolated printed page with a tilde', function (): void {
    $chunk = citedChunk(['printed_pages_estimated' => true]);

    expect($chunk->pages)->toBe('pp. ~107–~108 (PDF pp. 119–120)');
});

/**
 * Cafe Royal yields 13 folios across 265 pages and no two agree, so its
 * citations carry the page anyone can verify by opening the file.
 */
it('falls back to the pdf page when a book has no printed labels', function (): void {
    $chunk = citedChunk(['printed_page_from' => null, 'printed_page_to' => null]);

    expect($chunk->pages)->toBe('PDF pp. 119–120')
        ->and($chunk->citation)->toContain('PDF pp. 119–120');
});

it('reads as one line of citation', function (): void {
    expect(citedChunk()->citation)
        ->toBe('Old Waldorf Bar Days (1931), "Concerning the Curriculum", pp. 107–108 (PDF pp. 119–120)');
});

it('omits the section from a citation that has none', function (): void {
    $chunk = citedChunk(['section_title' => null]);

    expect($chunk->citation)->toBe('Old Waldorf Bar Days (1931), pp. 107–108 (PDF pp. 119–120)');
});

/**
 * The Python original lifted the heading out of the body and then embedded the
 * body alone, so "Blue Lady" was unsearchable in a book that is nothing but
 * drink names.
 */
it('puts the book, section and heading into the embedded string', function (): void {
    $chunk = citedChunk([
        'heading' => 'BLUE LADY',
        'text' => "BLUE LADY 1/2 Blue Curasao (Gamier).\nShake and strain.",
    ]);

    $embedded = $chunk->embeddingText();

    expect($embedded)->toStartWith('Old Waldorf Bar Days (1931) — Concerning the Curriculum — BLUE LADY')
        ->and($embedded)->toContain('BLUE LADY 1/2 Blue Curasao')
        // The heading stays in the text as well as the prefix.
        ->and($chunk->text)->toStartWith('BLUE LADY');
});

it('embeds the text alone when there is no provenance to add', function (): void {
    $book = Book::factory()->create(['title' => '', 'year' => null]);
    $chunk = BookChunk::factory()->for($book)->create(['section_title' => null, 'heading' => null, 'text' => 'Plain text.']);

    expect($chunk->embeddingText())->toBe('Plain text.');
});

/**
 * SimilaritySearch never calls with(), so without $with every citation costs a
 * query per chunk to name the book it came from.
 */
it('resolves a whole page of citations without an n plus one', function (): void {
    $book = Book::factory()->create(['title' => 'A Book', 'year' => 1900]);
    BookChunk::factory()->count(8)->for($book)->create();

    DB::enableQueryLog();

    $payloads = BookChunk::query()->limit(8)->get()
        ->map(fn (BookChunk $chunk): array => Arr::except($chunk->toArray(), ['embedding']))
        ->all();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($payloads)->toHaveCount(8)
        ->and($payloads[0]['book_title'])->toBe('A Book')
        // One for the chunks, one for the eager-loaded books.
        ->and($queries)->toHaveCount(2);
});

/**
 * Nothing is deleted for being unindexable, so the retrieval query is what
 * excludes it -- exactly as SimilaritySearch's own query hook would.
 */
it('lets a query exclude the chunks that should not be retrieved', function (): void {
    $book = Book::factory()->create();
    BookChunk::factory()->count(3)->for($book)->create();
    BookChunk::factory()->count(2)->for($book)->excluded()->create();

    expect(BookChunk::count())->toBe(5)
        ->and(BookChunk::where('is_indexable', true)->count())->toBe(3);
});
