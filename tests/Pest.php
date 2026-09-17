<?php

use App\Enums\PageStatus;
use App\Models\Book;
use App\Models\BookChunk;
use App\Models\BookPage;
use App\Models\Drink;
use App\Models\DrinkMention;
use App\Services\Retrieval\DrinkSurveyor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Gateway\FakeEmbeddingGateway;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // No test may reach TEI, or any other service. phpunit.xml points the
        // TEI URLs at an unroutable host as a second backstop, but this is the
        // one that fails with a readable message naming the URL.
        Http::preventStrayRequests();
    })
    ->in('Feature');

// Unit tests boot the application without touching the database, so that the
// services under test can read their configuration.
pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Load a book's real page text from tests/Fixtures/Books.
 *
 * The fixtures are verbatim exports of promoted book_pages rows, named for the
 * page they came from, so a failing assertion can be checked against the actual
 * scan. Models are built unsaved, which lets the whole chunking pipeline be
 * exercised from tests/Unit without a database.
 *
 * @param  list<int>|null  $pageNumbers  defaults to every page in the fixture directory
 * @return Collection<int, BookPage>
 */
function fixturePages(string $slug, ?array $pageNumbers = null): Collection
{
    $directory = __DIR__.'/Fixtures/Books/'.$slug;

    if ($pageNumbers === null) {
        $pageNumbers = collect(glob($directory.'/page-*.txt') ?: [])
            ->map(fn (string $path): int => (int) filter_var(basename($path), FILTER_SANITIZE_NUMBER_INT))
            ->sort()
            ->values()
            ->all();
    }

    return collect($pageNumbers)->map(function (int $pageNumber) use ($directory, $slug): BookPage {
        $path = $directory.'/page-'.$pageNumber.'.txt';

        if (! is_file($path)) {
            throw new RuntimeException("No fixture for {$slug} page {$pageNumber}.");
        }

        // Page text is stored trimmed, so the file's trailing newline -- which
        // is there because these are text files -- is not part of it.
        $text = rtrim((string) file_get_contents($path), "\n");

        return new BookPage([
            'page_number' => $pageNumber,
            'text' => $text,
            'char_count' => mb_strlen($text),
            'word_count' => count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []),
            'status' => PageStatus::Extracted,
            'printed_page_label' => null,
        ]);
    })->values();
}

/**
 * The observed printed page labels captured alongside a book's fixture pages.
 *
 * @return array<int, string>
 */
function fixtureLabels(string $slug): array
{
    $path = __DIR__.'/Fixtures/Books/'.$slug.'/labels.json';

    if (! is_file($path)) {
        throw new RuntimeException("No label fixture for {$slug}.");
    }

    /** @var array<string, string> $labels */
    $labels = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    $keyed = [];

    foreach ($labels as $pageNumber => $label) {
        $keyed[(int) $pageNumber] = (string) $label;
    }

    return $keyed;
}

/**
 * Build unsaved pages carrying only printed labels, for the label index.
 *
 * @param  array<int, string>  $labels
 * @return Collection<int, BookPage>
 */
function pagesWithLabels(array $labels, int $through): Collection
{
    return collect(range(1, $through))->map(fn (int $pageNumber): BookPage => new BookPage([
        'page_number' => $pageNumber,
        'text' => 'Page text.',
        'status' => PageStatus::Extracted,
        'printed_page_label' => $labels[$pageNumber] ?? null,
    ]))->values();
}

/**
 * A one-hot vector of the configured width.
 *
 * Random vectors make ordering assertions flaky at the margins: two random
 * 1024-dimensional vectors are nearly orthogonal, but "nearly" is not a number
 * a test can assert on. Cosine similarity between two one-hot vectors is
 * exactly 1 when the axes match and exactly 0 when they do not, so a test can
 * say which chunk comes back first and mean it.
 *
 * @return list<float>
 */
function unitVector(int $axis, ?int $dimensions = null): array
{
    $dimensions ??= (int) config('books.embedding.dimensions');

    $vector = array_fill(0, $dimensions, 0.0);
    $vector[$axis % $dimensions] = 1.0;

    return $vector;
}

/**
 * Fake embeddings generation, optionally mapping each input to a fixed vector.
 *
 * Embeddings::fake() clones the *resolved* provider, so the fake gateway reads
 * dimensions off the real configuration and hands back 1024-wide vectors with
 * nothing said here. Pass a map to pin specific inputs; anything unmapped gets
 * a random unit vector of the right width.
 *
 * @param  array<string, list<float>>  $vectors  keyed by a substring of the input
 */
function fakeEmbeddings(array $vectors = []): FakeEmbeddingGateway
{
    if ($vectors === []) {
        return Embeddings::fake();
    }

    return Embeddings::fake(function ($prompt) use ($vectors): array {
        return array_map(function (string $input) use ($vectors): array {
            foreach ($vectors as $needle => $vector) {
                if (str_contains($input, $needle)) {
                    return $vector;
                }
            }

            return Embeddings::fakeEmbedding((int) config('books.embedding.dimensions'));
        }, $prompt->inputs);
    });
}

/**
 * A tallied drink with real mentions behind it, citations and all.
 *
 * Shared rather than defined in one test file, because both the payload tests
 * and the tool tests need the same shape and either has to be runnable on its
 * own with --filter.
 */
function tallied(array $drinkOverrides = [], int $books = 1): Drink
{
    $drink = Drink::factory()->named('Blue Lady')->create($drinkOverrides + [
        'mention_count' => $books,
        'book_count' => $books,
        'first_year' => 1931,
        'last_year' => 1931,
    ]);

    for ($index = 0; $index < $books; $index++) {
        $book = Book::factory()->create([
            'title' => 'Old Waldorf Bar Days',
            'author' => 'Albert Stevens Crockett',
            'year' => 1931 + $index,
        ]);

        $chunk = BookChunk::factory()->for($book)->create([
            'section_title' => 'Concerning the Curriculum',
            'text' => 'BLUE LADY 1/2 Blue Curaçao.',
            'page_from' => 119,
            'page_to' => 119,
            'printed_page_from' => '107',
            'printed_page_to' => '107',
        ]);

        DrinkMention::factory()->for($drink)->forChunk($chunk, 'BLUE LADY')->create();
    }

    return $drink->fresh();
}

/**
 * One drink printed once in each of the given years, one book per year.
 *
 * The shape a windowed survey needs: aggregates on the row describe the whole
 * span, and a question bounded to part of it must count only the part. Tests
 * that assert "3 of my 27 books" against "1860-1869" need both numbers to be
 * real, which means real mentions carrying real book_years.
 *
 * @param  list<int>  $years
 */
function talliedAcross(string $name, array $years): Drink
{
    $drink = Drink::factory()->named($name)->create([
        'mention_count' => count($years),
        'book_count' => count($years),
        'first_year' => min($years),
        'last_year' => max($years),
    ]);

    foreach ($years as $index => $year) {
        $book = Book::factory()->create([
            'title' => "A Manual of {$year}",
            'author' => 'A Bartender',
            'year' => $year,
        ]);

        $chunk = BookChunk::factory()->for($book)->create([
            'text' => strtoupper($name).' 1/2 Curaçao.',
            'page_from' => 10 + $index,
            'page_to' => 10 + $index,
        ]);

        DrinkMention::factory()->for($drink)->forChunk($chunk, strtoupper($name))->create();
    }

    return $drink->fresh();
}

function surveyor(): DrinkSurveyor
{
    return app(DrinkSurveyor::class);
}
