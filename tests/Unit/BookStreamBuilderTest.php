<?php

use App\Enums\ChunkStrategy;
use App\Enums\PageStatus;
use App\Enums\SectionKind;
use App\Models\Book;
use App\Models\BookPage;
use App\Services\Books\BookStreamBuilder;
use App\Services\Books\DetectedSection;
use App\Services\Books\DetectedStructure;
use App\Services\Books\HeadEdge;
use App\Services\Books\PageLabelIndex;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    $this->builder = new BookStreamBuilder;
    $this->book = new Book(['title' => 'A Book', 'year' => 1900]);
});

/**
 * @param  array<int, string>  $texts
 * @return Collection<int, BookPage>
 */
function streamPages(array $texts, array $blank = []): Collection
{
    return collect($texts)->map(fn (string $text, int $pageNumber): BookPage => new BookPage([
        'page_number' => $pageNumber,
        'text' => $text,
        'status' => in_array($pageNumber, $blank, true) ? PageStatus::Blank : PageStatus::Extracted,
        'printed_page_label' => null,
    ]))->values();
}

function emptyStructure(array $headByPage = []): DetectedStructure
{
    return new DetectedStructure(
        [new DetectedSection(null, SectionKind::Body, 1, 99)],
        $headByPage,
        HeadEdge::Top,
        ChunkStrategy::Packing,
    );
}

/**
 * 127 pages in this corpus end mid-word. The page normalizer rejoins hyphens
 * within a page but by construction cannot see across the seam.
 */
it('rejoins a word broken across a page turn', function (): void {
    $pages = streamPages([1 => 'Serve in a cock-', 2 => 'tail glass at once.']);

    $stream = $this->builder->build($this->book, $pages, emptyStructure(), PageLabelIndex::forBook($pages));

    expect($stream->text)->toContain('cocktail glass');
});

/**
 * 1,445 pages (29%) end mid-sentence. A blank line there would force a chunk
 * boundary and sever the sentence, which is what assembling the book whole
 * exists to prevent.
 */
it('continues a sentence across a page turn with a single space', function (): void {
    $pages = streamPages([1 => 'In Havana you take the same thousand-dollar bill,', 2 => 'and you say nothing at all.']);

    $stream = $this->builder->build($this->book, $pages, emptyStructure(), PageLabelIndex::forBook($pages));

    expect($stream->text)->toBe('In Havana you take the same thousand-dollar bill, and you say nothing at all.');
});

it('separates two finished pages with a blank line', function (): void {
    $pages = streamPages([1 => 'A finished thought.', 2 => 'Another one.']);

    $stream = $this->builder->build($this->book, $pages, emptyStructure(), PageLabelIndex::forBook($pages));

    expect($stream->text)->toBe("A finished thought.\n\nAnother one.");
});

it('records where each page landed in the stream', function (): void {
    $pages = streamPages([1 => 'First page.', 2 => 'Second page.']);

    $stream = $this->builder->build($this->book, $pages, emptyStructure(), PageLabelIndex::forBook($pages));

    [$first, $second] = $stream->pages;

    expect($stream->slice($first->start, $first->end))->toBe('First page.')
        ->and($stream->slice($second->start, $second->end))->toBe('Second page.')
        // The separator belongs to neither page, so a one-character join can
        // never mis-attribute a page.
        ->and($second->start)->toBeGreaterThan($first->end);
});

/**
 * A blank leaf contributes nothing but must not disappear, or a passage running
 * across it would under-report its own page range.
 */
it('keeps a blank leaf in the page index so a range spans it', function (): void {
    $pages = streamPages([1 => 'Before the plate.', 2 => '', 3 => 'After the plate.'], blank: [2]);

    $stream = $this->builder->build($this->book, $pages, emptyStructure(), PageLabelIndex::forBook($pages));

    expect($stream->pages)->toHaveCount(3);

    $range = $stream->pageRangeFor(0, $stream->length());

    expect($range->pageFrom)->toBe(1)
        ->and($range->pageTo)->toBe(3);
});

it('removes a running head the detector proved redundant', function (): void {
    $pages = streamPages([1 => "OLD WALDORF BAR DAYS\nThe body of the page.", 2 => 'More body.']);

    $stream = $this->builder->build(
        $this->book,
        $pages,
        emptyStructure([1 => 'OLD WALDORF BAR DAYS']),
        PageLabelIndex::forBook($pages),
    );

    expect($stream->text)->not->toContain('OLD WALDORF BAR DAYS')
        ->and($stream->text)->toContain('The body of the page.');
});

it('keeps a head the detector did not name', function (): void {
    $pages = streamPages([1 => "GIN SLING.\nThe body of the page."]);

    $stream = $this->builder->build($this->book, $pages, emptyStructure(), PageLabelIndex::forBook($pages));

    expect($stream->text)->toContain('GIN SLING.');
});

/**
 * Old Waldorf prints its folio as "[ 108 |". Phase 1's pattern is stricter and
 * leaves it in place, so it would otherwise sit inside a chunk and go to waste
 * as citation evidence.
 */
it('lifts a bracketed folio out of the text and reads it as a page label', function (): void {
    $pages = streamPages([
        19 => "Some prose here.\n[ 7 |",
        20 => "More prose here.\n[ 8 |",
        21 => "Yet more prose.\n[ 9 |",
    ]);

    $labels = PageLabelIndex::forBook($pages);
    expect($labels->observations())->toBe([]);

    $stream = $this->builder->build($this->book, $pages, emptyStructure(), $labels);

    expect($stream->text)->not->toContain('[ 7 |')
        ->and($stream->text)->toContain('Some prose here.')
        ->and($labels->labelFor(20)->value)->toBe('8');
});

it('gives the whole stream a checksum that changes with its text', function (): void {
    $one = $this->builder->build($this->book, streamPages([1 => 'Text.']), emptyStructure(), PageLabelIndex::forBook(streamPages([1 => 'Text.'])));
    $two = $this->builder->build($this->book, streamPages([1 => 'Other.']), emptyStructure(), PageLabelIndex::forBook(streamPages([1 => 'Other.'])));

    expect($one->checksum)->not->toBe($two->checksum)
        ->and($one->checksum)->toHaveLength(64);
});
