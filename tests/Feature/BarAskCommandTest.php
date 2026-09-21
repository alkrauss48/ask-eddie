<?php

use App\Agents\EddieAgent;
use App\Agents\SashaAgent;
use App\Models\Book;
use App\Models\BookChunk;
use App\Models\HouseChunk;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;

beforeEach(function (): void {
    app()->bind(Reranker::class, fn (): Reranker => new NullReranker);
});

function askableCorpus(): BookChunk
{
    $book = Book::factory()->create([
        'slug' => 'old-waldorf-bar-days-1931',
        'title' => 'Old Waldorf Bar Days',
        'year' => 1931,
    ]);

    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));

    return BookChunk::factory()->for($book)->embedded(unitVector(1))->create([
        'heading' => 'BLUE LADY',
        'headings' => ['BLUE LADY'],
        'section_title' => null,
        'text' => "BLUE LADY 1/2 Blue Curaçao.\n1/4 Booth's Gin.\nShake and strain.",
        'page_from' => 119,
        'page_to' => 119,
        'printed_page_from' => '107',
        'printed_page_to' => '107',
    ]);
}

/**
 * The table truncates to the terminal width, so this asserts the columns the
 * command uniquely adds rather than the citation text -- which is pinned
 * against truncation in BookChunkCitationTest and SearchTheBooksToolTest.
 */
it('names the book and both channels for a retrieved passage', function (): void {
    askableCorpus();

    $this->artisan('bar:ask', [
        'question' => ['what', 'goes', 'in', 'a', 'Blue', 'Lady?'],
        '--retrieval-only' => true,
    ])
        // Each expectation is matched against a single write, and the channel
        // summary arrives as one, so this asserts the table and the summary
        // rather than both channel lines separately.
        ->expectsOutputToContain('Old Waldorf Bar Days')
        ->expectsOutputToContain('dense: 1 of 1')
        ->assertSuccessful();
});

/**
 * The reason the per-channel ranks survive fusion at all. A hybrid search where
 * one channel silently returns nothing answers questions perfectly well,
 * slightly worse, and no single answer reveals it.
 */
it('says out loud when a channel contributed nothing', function (): void {
    $book = Book::factory()->create(['title' => 'A Book', 'year' => 1900]);
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));

    // Found by the vector, but sharing no word with the question.
    BookChunk::factory()->for($book)->embedded(unitVector(1))->create([
        'heading' => null,
        'headings' => null,
        'text' => 'Something else entirely.',
    ]);

    $this->artisan('bar:ask', [
        'question' => ['absinthe'],
        '--retrieval-only' => true,
    ])
        ->expectsOutputToContain('contributed nothing')
        ->assertSuccessful();
});

it('fails when nothing is retrieved', function (): void {
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));

    $this->artisan('bar:ask', [
        'question' => ['anything', 'at', 'all'],
        '--retrieval-only' => true,
    ])
        ->expectsOutputToContain('Nothing retrieved')
        ->assertFailed();
});

it('honours an explicit limit', function (): void {
    $book = Book::factory()->create(['title' => 'A Book', 'year' => 1900]);
    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));
    BookChunk::factory()->count(6)->for($book)->embedded(unitVector(1))->create(['text' => 'Gin.']);

    $this->artisan('bar:ask', [
        'question' => ['gin'],
        '--retrieval-only' => true,
        '--limit' => 2,
    ])->assertSuccessful()
        // Two rows and no third.
        ->doesntExpectOutputToContain('| 3     |');
});

/**
 * A retrieval failure must name the likely cause rather than surfacing a raw
 * connection exception, because the likely cause is always the same one.
 */
it('reports a retrieval failure without a stack trace', function (): void {
    Embeddings::fake(fn (): never => throw new RuntimeException('Connection refused'));

    $this->artisan('bar:ask', [
        'question' => ['gin'],
        '--retrieval-only' => true,
    ])
        ->expectsOutputToContain('Retrieval failed')
        ->expectsOutputToContain('books:doctor')
        ->assertFailed();
});

/**
 * Eddie's answer is written to the terminal as it arrives rather than after it
 * is finished, which with two tool round-trips in front of it is the difference
 * between a pause and an apparent hang.
 *
 * Asserted through Artisan::output() rather than expectsOutputToContain(),
 * because that assertion is matched against a single write and a streamed
 * sentence arrives one word per write -- which is also what proves the answer
 * was streamed rather than printed in a block.
 */
it('writes the answer as it arrives', function (): void {
    EddieAgent::fake(["That one's out of the Savoy, friend."]);

    $status = Artisan::call('bar:ask', ['question' => ['what', 'goes', 'in', 'a', 'Blue', 'Lady?']]);

    expect($status)->toBe(0)
        ->and(Artisan::output())->toContain("  That one's out of the Savoy, friend.");

    EddieAgent::assertPrompted('what goes in a Blue Lady?');
});

/**
 * The half of the previous test that Artisan::output() cannot see. Each console
 * expectation is matched against a single write, so a sentence that reaches the
 * buffer while no one write contains it is a sentence that was streamed --
 * which the blocking path could not have produced.
 */
it('writes the answer in pieces rather than in a block', function (): void {
    EddieAgent::fake(["That one's out of the Savoy, friend."]);

    // Nothing may also expect a substring of this one: Mockery matches a write
    // against the first expectation that accepts it, so an overlapping
    // expectsOutputToContain() would absorb the write and this would pass
    // whatever the command did.
    $this->artisan('bar:ask', ['question' => ['what', 'goes', 'in', 'a', 'Blue', 'Lady?']])
        ->doesntExpectOutputToContain('out of the Savoy, friend.')
        ->assertSuccessful();
});

/**
 * A stream can fail half a sentence in, where prompt() could only fail before a
 * word had been printed. The error must not land on the end of Eddie's.
 */
it('reports a failure without a stack trace', function (): void {
    EddieAgent::fake(fn (): never => throw new RuntimeException('The provider hung up'));

    $this->artisan('bar:ask', ['question' => ['anything']])
        ->expectsOutputToContain('Eddie could not answer')
        ->assertFailed();
});

/**
 * The roster itself is asserted in EddieAgentTest; what matters here is that
 * the command hands Eddie tools rather than letting him answer from memory.
 */
it('gives eddie the books rather than his own recollection', function (): void {
    $tools = iterator_to_array(app(EddieAgent::class)->tools());

    expect($tools)->not->toBeEmpty()
        ->and($tools)->toContainOnlyInstancesOf(Tool::class);
});

/**
 * The two halves of the persona are in tension on purpose -- "invent one on the
 * spot" against "never attribute anything to a book you did not read here" --
 * so the boundary between them is stated rather than left to the model to
 * infer. Losing either half loses the product.
 */
it('tells eddie to search before answering and never to invent a source', function (): void {
    $instructions = (string) app(EddieAgent::class)->instructions();

    expect($instructions)->toContain('You are Eddie')
        // Substrings that do not span the heredoc's line wraps.
        ->and($instructions)->toContain('search tool before answering')
        ->and($instructions)->toContain('Never attribute a drink')
        ->and($instructions)->toContain('Inventing a drink is part of the job');
});

/**
 * --bartender selects the agent *and* the retriever together, and the pairing
 * is the whole reason the registry is one row per bartender rather than two
 * options. Sasha's --sources showing Eddie's passages would be a table of real
 * citations from the wrong corpus, with nothing on screen to say so.
 */
it('retrieves from the house when sasha is asked', function (): void {
    config(['house.retrieval.rerank.enabled' => false]);

    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));

    HouseChunk::factory()->embedded(unitVector(1))->create([
        'title' => 'Midnight Rambler',
        'text' => "Midnight Rambler\n\n2oz Rye Whiskey, .5oz Blackberry Syrup.",
        'keywords' => ['Rye Whiskey'],
        'url' => 'https://thekrausshaus.com/cocktails/midnight-rambler',
    ]);

    // A book chunk that would win every channel if the wrong retriever ran.
    askableCorpus();

    $this->artisan('bar:ask', [
        'question' => ['what', 'is', 'the', 'midnight', 'rambler'],
        '--bartender' => 'sasha',
        '--retrieval-only' => true,
    ])
        ->expectsOutputToContain('Midnight Rambler')
        ->doesntExpectOutputToContain('Old Waldorf Bar Days')
        ->assertSuccessful();
});

it('still retrieves from the books when eddie is asked', function (): void {
    askableCorpus();

    HouseChunk::factory()->embedded(unitVector(1))->create([
        'title' => 'Midnight Rambler',
        'text' => 'Rye, blackberry, lemon.',
        'keywords' => [],
    ]);

    $this->artisan('bar:ask', [
        'question' => ['what', 'goes', 'in', 'a', 'Blue', 'Lady?'],
        '--bartender' => 'eddie',
        '--retrieval-only' => true,
    ])
        ->expectsOutputToContain('Old Waldorf Bar Days')
        ->doesntExpectOutputToContain('Midnight Rambler')
        ->assertSuccessful();
});

/**
 * Eddie by default, because `eddie:ask "gin fizz"` should keep working as
 * `bar:ask "gin fizz"` with nothing else typed.
 */
it('pours from eddie when nobody is named', function (): void {
    askableCorpus();

    expect(config('bar.default'))->toBe('eddie');

    $this->artisan('bar:ask', [
        'question' => ['what', 'goes', 'in', 'a', 'Blue', 'Lady?'],
        '--retrieval-only' => true,
    ])
        ->expectsOutputToContain('Old Waldorf Bar Days')
        ->assertSuccessful();
});

it('says who works here when asked for somebody who does not', function (): void {
    $this->artisan('bar:ask', [
        'question' => ['anything'],
        '--bartender' => 'gus',
    ])
        ->expectsOutputToContain('Nobody called "gus" works here')
        ->expectsOutputToContain('eddie, sasha')
        ->assertFailed();
});

it('names the right doctor command for the corpus that failed', function (): void {
    Embeddings::fake(fn (): never => throw new RuntimeException('Connection refused'));

    $this->artisan('bar:ask', [
        'question' => ['rum'],
        '--bartender' => 'sasha',
        '--retrieval-only' => true,
    ])
        ->expectsOutputToContain('Retrieval failed')
        ->expectsOutputToContain('house:status')
        ->assertFailed();
});

it('streams sashas answer and names her when she cannot give one', function (): void {
    SashaAgent::fake(['That one I can do — it is on the spring menu.']);

    $status = Artisan::call('bar:ask', [
        'question' => ['something', 'bright'],
        '--bartender' => 'sasha',
    ]);

    expect($status)->toBe(0)
        ->and(Artisan::output())->toContain('it is on the spring menu');

    SashaAgent::assertPrompted('something bright');
});

it('reports sashas failure under her own name', function (): void {
    SashaAgent::fake(fn (): never => throw new RuntimeException('The provider hung up'));

    $this->artisan('bar:ask', ['question' => ['anything'], '--bartender' => 'sasha'])
        ->expectsOutputToContain('Sasha could not answer')
        ->assertFailed();
});

/**
 * The tool labels moved out of AnswerStream and into configuration when the
 * second bartender arrived. Both rosters have to be in there, or a guest sees a
 * class name where a status line should be.
 */
it('has an in-character label for every tool either bartender carries', function (): void {
    $tools = collect([...app(EddieAgent::class)->tools(), ...app(SashaAgent::class)->tools()])
        ->map(fn (object $tool): string => class_basename($tool))
        ->all();

    expect(array_keys((array) config('bar.labels')))->toEqualCanonicalizing($tools);
});

/**
 * The most valuable test in Phase D, and it is possible because ToolResult
 * stream events are emitted by TextGenerationLoop rather than by the gateway.
 * Faking Eddie into calling AskSasha runs the **real** tool, through the real
 * desk, against a faked Sasha -- so a real ToolResult flows through the real
 * AnswerStream and out to the real terminal rendering. Nothing in the path
 * between the two bartenders is stubbed.
 */
it('renders a consult inline, attributed, and exactly once', function (): void {
    SashaAgent::fake(["Rye and blackberry, stirred. It's the Midnight Rambler."]);
    EddieAgent::fake([
        new ToolCallData('c1', 'AskSasha', ['question' => 'What would a modern bar do with rye?']),
        "That's Sasha's, over at the house. My books have nothing like it.",
    ]);

    $status = Artisan::call('bar:ask', ['question' => ['what', 'would', 'a', 'modern', 'bartender', 'do?']]);
    $output = Artisan::output();

    expect($status)->toBe(0)
        ->and($output)->toContain('⋯ calling Sasha over')
        ->and($output)->toContain('— Sasha says —')
        ->and($output)->toContain('│ Rye and blackberry, stirred.')
        ->and($output)->toContain("That's Sasha's, over at the house.")
        // Once. A quoted block printed twice would read as Sasha repeating
        // herself, and is what a stream that both rendered and re-emitted the
        // result would produce.
        ->and(substr_count($output, '— Sasha says —'))->toBe(1);

    SashaAgent::assertPromptedTimes(1);
    SashaAgent::assertPrompted('What would a modern bar do with rye?');
});

it('lets sasha call eddie over the same way', function (): void {
    EddieAgent::fake(["That one's out of the Savoy, 1930, page 42."]);
    SashaAgent::fake([
        new ToolCallData('c1', 'AskEddie', ['question' => 'Where does the Sazerac come from?']),
        'Eddie has it in the Savoy. We do not pour it here.',
    ]);

    Artisan::call('bar:ask', ['question' => ['where', 'is', 'the', 'sazerac', 'from?'], '--bartender' => 'sasha']);

    expect(Artisan::output())
        ->toContain('⋯ calling Eddie over')
        ->toContain('— Eddie says —')
        ->toContain('│ That one\'s out of the Savoy, 1930, page 42.');
});

/**
 * A consult is the only tool result a guest ever sees. Everything else still
 * falls through the existing `continue`, and the four-callback signature must
 * not have widened that hole.
 */
it('still keeps a passage payload off the terminal while consults render', function (): void {
    EddieAgent::fake([
        new ToolCallData('c1', 'SearchTheBooks', ['query' => 'blue lady']),
        'Gin and curaçao, friend.',
    ]);

    Artisan::call('bar:ask', ['question' => ['blue', 'lady']]);

    expect(Artisan::output())
        ->toContain('⋯ reaching for the books')
        ->not->toContain('— Sasha says —')
        ->not->toContain('│ ');
});

/**
 * Fail-closed, all the way to the terminal. The other bar being unreachable
 * costs a capability and prints a sentence a guest can read -- never a stack
 * trace, and never the raw exception message laravel/ai's own AgentTool would
 * have handed back.
 */
it('prints a readable sentence when the other bar cannot be reached', function (): void {
    SashaAgent::fake(fn (): never => throw new RuntimeException('Connection refused by 10.0.0.4'));
    EddieAgent::fake([
        new ToolCallData('c1', 'AskSasha', ['question' => 'anything']),
        'She is not picking up, friend. Let me tell you what my own books say.',
    ]);

    $status = Artisan::call('bar:ask', ['question' => ['ask', 'sasha']]);

    expect($status)->toBe(0)
        ->and(Artisan::output())
        ->toContain('The line to the house bar is dead tonight')
        ->not->toContain('Connection refused');
});
