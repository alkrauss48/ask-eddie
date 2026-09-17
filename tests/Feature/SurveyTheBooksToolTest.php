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
