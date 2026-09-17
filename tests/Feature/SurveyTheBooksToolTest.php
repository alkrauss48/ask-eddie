<?php

use App\Models\Book;
use App\Models\Drink;
use App\Services\Retrieval\DrinkQuery;
use App\Services\Retrieval\DrinkSurveyor;
use App\Tools\SurveyTheBooks;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;

function surveyTool(): SurveyTheBooks
{
    return app(SurveyTheBooks::class);
}

function surveyRows(string $output): array
{
    return json_decode(substr($output, (int) strpos($output, '[')), true, flags: JSON_THROW_ON_ERROR);
}

it('parses as json after a preamble that names its coverage', function (): void {
    tallied();

    $output = surveyTool()->handle(new Request([]));

    expect($output)->toStartWith('Tallied across')
        ->and(surveyRows($output))->toBeArray()
        ->and(json_last_error())->toBe(JSON_ERROR_NONE);
});

it('returns rows of exactly six keys', function (): void {
    tallied();

    foreach (surveyRows(surveyTool()->handle(new Request([]))) as $row) {
        expect(array_keys($row))->toEqualCanonicalizing([
            'name', 'books', 'mentions', 'years', 'also_printed_as', 'citations',
        ])->and($row)->toHaveCount(6);
    }
});

it('leaks no bookkeeping into the prompt', function (): void {
    tallied();

    $output = strtolower(surveyTool()->handle(new Request([])));

    foreach (['"id"', 'slug', 'canonical_key', 'score', 'rank', 'embedding'] as $leak) {
        expect($output)->not->toContain($leak);
    }
});

/**
 * The single most important line in the feature. Without it, "what comes up
 * time and again" silently means "across the books that print headings", and
 * Eddie states a corpus-wide claim he cannot support.
 */
it('tells the model how much of the shelf it counted', function (): void {
    tallied();
    Book::factory()->count(3)->create();

    $output = surveyTool()->handle(new Request([]));

    expect($output)->toContain('of the 4 books on the shelf')
        ->and($output)->toContain('print no drink headings to count');
});

/**
 * Real corpus data defeated the first version of this: every book yields at
 * least one name, so "all 102 books" was both true and misleading.
 */
it('says where the count came from, not just how many books were opened', function (): void {
    tallied(['canonical_key' => 'ginfizz', 'slug' => 'gin-fizz'], books: 9);
    tallied(['canonical_key' => 'bluelady', 'slug' => 'blue-lady'], books: 1);

    $output = surveyTool()->handle(new Request([]));

    expect($output)->toContain('not spread evenly');
});

it('ranks by books printed unless asked otherwise', function (): void {
    Drink::factory()->named('Gin Fizz')->create(['book_count' => 19, 'mention_count' => 34]);
    Drink::factory()->named('Blue Lady')->create(['book_count' => 2, 'mention_count' => 99]);

    $byBooks = surveyRows(surveyTool()->handle(new Request([])));
    $byMentions = surveyRows(surveyTool()->handle(new Request(['order' => 'mentions'])));

    expect($byBooks[0]['name'])->toBe('Gin Fizz')
        ->and($byMentions[0]['name'])->toBe('Blue Lady');
});

it('ranks by the year a drink was first or last printed', function (): void {
    Drink::factory()->named('Gin Fizz')->create(['first_year' => 1862, 'last_year' => 1900]);
    Drink::factory()->named('Blue Lady')->create(['first_year' => 1931, 'last_year' => 1937]);

    expect(surveyRows(surveyTool()->handle(new Request(['order' => 'earliest'])))[0]['name'])->toBe('Gin Fizz')
        ->and(surveyRows(surveyTool()->handle(new Request(['order' => 'latest'])))[0]['name'])->toBe('Blue Lady');
});

it('clamps a limit a model asks for beyond the ceiling', function (): void {
    Drink::factory()->count(30)->create();

    config()->set('books.drinks.survey.max_limit', 5);

    expect(surveyRows(surveyTool()->handle(new Request(['limit' => 500]))))->toHaveCount(5);
});

it('finds a drink through the same fold the corpus was stored under', function (): void {
    tallied();

    $rows = surveyRows(surveyTool()->handle(new Request(['name' => 'blue lady'])));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Blue Lady');
});

it('filters to the drinks a period printed', function (): void {
    tallied();

    expect(surveyRows(surveyTool()->handle(new Request(['from_year' => 1900, 'to_year' => 1940]))))
        ->toHaveCount(1);

    expect(surveyTool()->handle(new Request(['from_year' => 1950])))
        ->toContain('No drink on the shelf matches that');
});

it('filters to drinks printed in at least so many books', function (): void {
    Drink::factory()->named('Gin Fizz')->create(['book_count' => 19]);
    Drink::factory()->named('Blue Lady')->create(['book_count' => 1]);

    $rows = surveyRows(surveyTool()->handle(new Request(['min_books' => 5])));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Gin Fizz');
});

/**
 * An untallied shelf and a filter that matched nothing are different facts. If
 * they collapse into one sentence, Eddie reports an absence from the books when
 * what actually happened is that nobody counted them.
 */
it('says it has not counted, which is not the same as finding nothing', function (): void {
    $untallied = surveyTool()->handle(new Request([]));

    tallied();

    $nothingMatched = surveyTool()->handle(new Request(['name' => 'a drink that does not exist']));

    expect($untallied)->toContain('has not been tallied yet')
        ->and($nothingMatched)->toContain('No drink on the shelf matches that')
        ->and($untallied)->not->toBe($nothingMatched);
});

/**
 * The reranker's precedent: a counting outage costs a capability, never an
 * exception in the middle of answering a guest.
 */
it('fails closed when the tally cannot be read', function (): void {
    $this->mock(DrinkSurveyor::class)
        ->shouldReceive('coverage')
        ->andThrow(new RuntimeException('the tally is on fire'));

    $output = surveyTool()->handle(new Request([]));

    expect($output)->toContain('unavailable just now')
        ->and($output)->toContain('Do not guess a number');
});

it('describes itself in terms that make the model reach for it', function (): void {
    $description = (string) surveyTool()->description();

    expect($description)->toContain('books')
        ->and($description)->toContain('citations');
});

it('offers the model six optional fields and no more', function (): void {
    $schema = surveyTool()->schema(new JsonSchemaTypeFactory);

    expect(array_keys($schema))->toEqualCanonicalizing([
        'name', 'order', 'limit', 'from_year', 'to_year', 'min_books',
    ]);
});

it('offers the model only the orders the surveyor implements', function (): void {
    $schema = surveyTool()->schema(new JsonSchemaTypeFactory);

    expect($schema['order']->toArray()['enum'])->toBe(DrinkQuery::ORDERS);
});

/**
 * The defect this replaced: from_year and to_year narrowed which drinks came
 * back and then ranked them on the corpus-wide column, so a survey of the 1860s
 * returned the same drinks in the same order as a survey of everything. The
 * question looked answered and was not.
 */
it('counts inside the window rather than filtering by it', function (): void {
    // Everywhere across the whole span, but only once in the sixties.
    talliedAcross('Gin Fizz', [1862, 1890, 1900, 1910, 1920]);
    // Rarer overall, and the drink that decade actually printed.
    talliedAcross('Whiskey Sour', [1862, 1866, 1868]);

    $rows = surveyRows(surveyTool()->handle(new Request(['from_year' => 1860, 'to_year' => 1869])));

    expect($rows[0]['name'])->toBe('Whiskey Sour')
        ->and($rows[0]['books'])->toBe(3)
        ->and($rows[1]['name'])->toBe('Gin Fizz')
        // One, not the five the whole shelf prints it in.
        ->and($rows[1]['books'])->toBe(1);
});

it('reports the years the window holds, not the drink whole span', function (): void {
    talliedAcross('Gin Fizz', [1862, 1890, 1930]);

    $rows = surveyRows(surveyTool()->handle(new Request(['from_year' => 1880, 'to_year' => 1900])));

    expect($rows[0]['years'])->toBe('1890');
});

/**
 * A citation from outside the window is a true sentence about the wrong books.
 */
it('cites only a printing the window contains', function (): void {
    talliedAcross('Gin Fizz', [1862, 1935]);

    $rows = surveyRows(surveyTool()->handle(new Request(['from_year' => 1930])));

    expect($rows[0]['citations'])->toHaveCount(1)
        ->and($rows[0]['citations'][0])->toContain('1935')
        ->and($rows[0]['citations'][0])->not->toContain('1862');
});

it('frames a windowed tally by the books the window holds', function (): void {
    talliedAcross('Gin Fizz', [1862, 1866]);

    $output = surveyTool()->handle(new Request(['from_year' => 1860, 'to_year' => 1869]));

    expect($output)->toStartWith('Tallied over 1860–1869 only')
        ->and($output)->toContain('2 books')
        ->and($output)->toContain('not out of the whole shelf');
});

/**
 * first_year is the earliest book on this shelf that prints a drink, which is
 * not where the drink came from. The shelf is thin before 1880, so the two are
 * reliably different and Eddie has no way to tell from the rows alone.
 */
it('says that first and last printing are facts about the shelf', function (): void {
    tallied();

    $output = surveyTool()->handle(new Request(['order' => 'earliest']));

    expect($output)->toContain('not the same as when it was invented');
});

it('leaves the chronology caveat off a ranking that is not chronological', function (): void {
    tallied();

    expect(surveyTool()->handle(new Request(['order' => 'books'])))
        ->not->toContain('not the same as when it was invented');
});

/**
 * The tally counts drinks, and DrinkClassifier decides which rows are one. The
 * 7,011 single-book OCR artefacts stay in the database and out of every answer.
 */
it('never counts a row the classifier set aside', function (): void {
    Drink::factory()->named('Gin Fizz')->create(['book_count' => 19]);
    Drink::factory()->named('Thiet Dtn')->uncountable()->create(['book_count' => 40]);

    $rows = surveyRows(surveyTool()->handle(new Request([])));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Gin Fizz');
});

it('will not find a set-aside row even when asked for it by name', function (): void {
    Drink::factory()->named('Thiet Dtn')->uncountable()->create();

    expect(surveyTool()->handle(new Request(['name' => 'Thiet Dtn'])))
        ->toContain('No drink on the shelf matches that');
});
