<?php

use App\Enums\ChunkStrategy;
use App\Enums\PageStatus;
use App\Enums\SectionKind;
use App\Models\Book;
use App\Models\BookPage;
use App\Services\Books\DetectedSection;
use App\Services\Books\DetectedStructure;
use App\Services\Books\HeadingPatterns;
use App\Services\Books\SectionDetector;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    $this->detector = new SectionDetector(new HeadingPatterns);
});

/**
 * Old Waldorf Bar Days' 253 pages carry only 53 distinct first lines. Rebuilt
 * here from the real ones, so the clustering is exercised at full scale without
 * a database.
 *
 * @return Collection<int, BookPage>
 */
function waldorfPages(): Collection
{
    $lines = file(__DIR__.'/../Fixtures/Books/old-waldorf-bar-days-1931/first-lines.tsv', FILE_IGNORE_NEW_LINES) ?: [];

    return collect($lines)->map(function (string $line): BookPage {
        [$pageNumber, $head] = array_pad(explode("\t", $line, 2), 2, '');

        // A head plus body that differs on every page, so that the bottom edge
        // does not repeat and the edge-picker has a real choice to make.
        return new BookPage([
            'page_number' => (int) $pageNumber,
            'text' => $head."\n\nBody text for page {$pageNumber}, in complete sentences."
                ."\n\nA second paragraph, also unique to page {$pageNumber}.",
            'status' => PageStatus::Extracted,
        ]);
    })->values();
}

function detect(Collection $pages, string $title = 'A Book', ?int $year = 1900): DetectedStructure
{
    return test()->detector->detect(new Book(['title' => $title, 'year' => $year, 'slug' => 'a-book']), $pages);
}

/**
 * @return list<string>
 */
function chapterTitles(DetectedStructure $structure): array
{
    return array_values(array_map(
        fn (DetectedSection $s): string => (string) $s->title,
        array_filter($structure->sections, fn (DetectedSection $s): bool => $s->kind === SectionKind::Chapter),
    ));
}

it('reads a real book of chapters off its running heads', function (): void {
    $structure = detect(waldorfPages(), 'Old Waldorf Bar Days', 1931);

    expect(chapterTitles($structure))->toBe([
        'Many Schools in One',
        'Hall of Fame',
        'Bar Patterns',
        'Faculty and Proctors',
        'Concerning the Curriculum',
        'Cocktails',
        'Fancy Potations and Oth erwise',
        'Glossary',
    ]);
});

it('gives each detected chapter the page range its head covers', function (): void {
    $structure = detect(waldorfPages(), 'Old Waldorf Bar Days', 1931);

    $cocktails = collect($structure->sections)->firstWhere('title', 'Cocktails');

    expect($cocktails->pageFrom)->toBe(127)
        ->and($cocktails->pageTo)->toBe(185);
});

/**
 * The book's own title on 103 versos carries no structure and would otherwise
 * be repeated inside 103 chunks. It is recorded rather than merely dropped.
 */
it('records the book title as a running head rather than a chapter', function (): void {
    $structure = detect(waldorfPages(), 'Old Waldorf Bar Days', 1931);

    $heads = array_values(array_filter(
        $structure->sections,
        fn (DetectedSection $s): bool => $s->kind === SectionKind::RunningHead,
    ));

    expect($heads)->toHaveCount(1)
        ->and($heads[0]->title)->toBe('Old Waldorf Bar Days')
        // Every spelling that produced it, so the removal is on record.
        ->and(array_keys($heads[0]->headVariants))->toContain('Old Waldotf Bar Days');
});

it('folds ocr variants of one head into a single section', function (): void {
    $structure = detect(waldorfPages(), 'Old Waldorf Bar Days', 1931);

    $faculty = collect($structure->sections)->firstWhere('title', 'Faculty and Proctors');

    expect(array_keys($faculty->headVariants))->toContain('F acuity and Proctors');
});

/**
 * A recto-only chapter head sits entirely on one side of the spine -- and so
 * does a verso title. Testing page parity classified "Hall of Fame" and
 * "Cocktails" as noise, so it is not a signal.
 */
it('does not mistake a recto-only chapter head for the book title', function (): void {
    $pages = collect(range(1, 40))->map(fn (int $page): BookPage => new BookPage([
        'page_number' => $page,
        'text' => ($page % 2 === 1 && $page >= 11 && $page <= 31 ? "Hall of Fame\n\n" : '')
            ."Body text in complete sentences on page {$page}.",
        'status' => PageStatus::Extracted,
    ]))->values();

    expect(chapterTitles(detect($pages)))->toBe(['Hall of Fame']);
});

/**
 * Cafe Royal heads every page with the alphabetical range of the drinks on it,
 * giving 169 distinct heads across 265 pages. Grouped by stem they are a
 * handful of real sections instead of 169 junk ones.
 */
it('groups alphabetical guide words into one section per stem', function (): void {
    $ranges = ['BL-BO', 'BO-BR', 'BR-CA', 'CA-CH', 'CH-CL', 'CL-CO', 'CO-CR', 'CR-DA'];

    $pages = collect($ranges)->map(fn (string $range, int $i): BookPage => new BookPage([
        'page_number' => $i + 10,
        'text' => "COCKTAILS {$range}\n\nBLUE LADY NUMBER {$i} 1/2 Blue Curasao.\nShake and strain, page {$i}.",
        'status' => PageStatus::Extracted,
    ]))->values();

    $sections = array_values(array_filter(
        detect($pages, 'Cafe Royal Cocktail Book', 1937)->sections,
        fn (DetectedSection $s): bool => $s->kind === SectionKind::Section,
    ));

    expect($sections)->toHaveCount(1)
        ->and($sections[0]->title)->toBe('Cocktails');
});

it('invents no sections for a book that heads every page with a recipe name', function (): void {
    $names = ['PORTER SANGAREE.', 'GIN SLING.', 'WHISKEY TODDY.', 'APPLE TODDY.', 'BRANDY SMASH.', 'MINT JULEP.'];

    $pages = collect($names)->map(fn (string $name, int $i): BookPage => new BookPage([
        'page_number' => $i + 60,
        'text' => "{$name}\n\n128. Gin Sangaree.\n\n1 teaspoonful of sugar, page {$i}.",
        'status' => PageStatus::Extracted,
    ]))->values();

    expect(chapterTitles(detect($pages)))->toBe([]);
});

/**
 * A confidently wrong chapter name is worse in a citation than none, and the
 * Python title-cased whatever caps line it saw last -- producing names like
 * "Cocktails Bl-Bo".
 */
it('falls back to one untitled section when it finds no structure', function (): void {
    $pages = collect(range(1, 5))->map(fn (int $page): BookPage => new BookPage([
        'page_number' => $page,
        'text' => "Body text that differs on every page, number {$page}. Nothing repeats here at all.",
        'status' => PageStatus::Extracted,
    ]))->values();

    $structure = detect($pages);

    expect($structure->sections)->toHaveCount(1)
        ->and($structure->sections[0]->kind)->toBe(SectionKind::Body)
        ->and($structure->sections[0]->title)->toBeNull();
});

it('handles a book with no extracted pages', function (): void {
    $structure = detect(collect());

    expect($structure->sections)->toHaveCount(1)
        ->and($structure->strategy)->toBe(ChunkStrategy::Packing);
});

/**
 * The strategy is measured, not assigned. A dense recipe list takes the heading
 * path; narrative prose does not.
 */
it('sends a dense recipe list down the heading path', function (): void {
    $pages = collect(range(1, 10))->map(fn (int $page): BookPage => new BookPage([
        'page_number' => $page,
        'text' => "Body for page {$page}\n\nBLUE LADY 1/2 Blue Curasao.\nShake.\n\nBLUE PETER 1/4 Blue Curasao.\nMix.\n\nBLUE STAR 1/3 Gin.\nShake, page {$page}.",
        'status' => PageStatus::Extracted,
    ]))->values();

    expect(detect($pages)->strategy)->toBe(ChunkStrategy::Headings);
});

it('sends narrative prose down the packing path', function (): void {
    $pages = collect(range(1, 10))->map(fn (int $page): BookPage => new BookPage([
        'page_number' => $page,
        'text' => 'In Havana you take the same thousand-dollar bill and you pin it to your hat. '
            .'The men who drank there are gone now, and so is the bar they drank at. '
            ."What remains is the record on page {$page}, and the record is what this book is for.",
        'status' => PageStatus::Extracted,
    ]))->values();

    expect(detect($pages)->strategy)->toBe(ChunkStrategy::Packing);
});

it('lets configuration override the measured strategy', function (): void {
    config(['books.chunking.strategy_overrides' => ['a-book' => 'headings']]);

    $pages = collect(range(1, 5))->map(fn (int $page): BookPage => new BookPage([
        'page_number' => $page,
        'text' => "Plain narrative prose with no headings in it at all, on page {$page}.",
        'status' => PageStatus::Extracted,
    ]))->values();

    expect(detect($pages)->strategy)->toBe(ChunkStrategy::Headings);
});
