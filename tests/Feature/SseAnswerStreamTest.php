<?php

use App\Agents\EddieAgent;
use App\Agents\SashaAgent;
use App\Ai\Bar\Bartenders;
use App\Ai\Streaming\SseAnswerStream;
use App\Enums\HouseSourceType;
use App\Models\Book;
use App\Models\BookChunk;
use App\Models\HouseChunk;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
use Illuminate\Support\Collection;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\ToolResult;

beforeEach(function (): void {
    app()->bind(Reranker::class, fn (): Reranker => new NullReranker);

    // HouseRetriever gets its reranker through a contextual binding, so the
    // line above does not reach it. On this corpus the knob is the switch.
    config(['house.retrieval.rerank.enabled' => false]);
});

/**
 * Ask a bartender exactly the way the JSON API will, and keep the raw body.
 *
 * The response is handed back alongside it because StreamableAgentResponse
 * remembers the events it yielded, which is what lets a test say "the payload
 * really was in this run, and it really is not in what went out".
 *
 * @return array{string, StreamableAgentResponse}
 */
function sseRun(string $bartender, string $question): array
{
    $stream = app(Bartenders::class)->stream($bartender, $question);

    $frames = (new SseAnswerStream($stream, (array) config('bar.labels')))->stream();

    return [implode('', iterator_to_array($frames, false)), $stream];
}

/**
 * Read the body back as a client would: frames split on the blank line, each
 * one an event name and a JSON object.
 *
 * Splitting on "\n\n" is only safe because every payload is JSON-encoded, so
 * no frame can contain a bare newline -- which is itself the thing being
 * checked every time this is used.
 *
 * @return list<array{string, array<string, mixed>}>
 */
function sseParsed(string $body): array
{
    $frames = [];

    foreach (explode("\n\n", $body) as $chunk) {
        if (trim($chunk) === '') {
            continue;
        }

        [$event, $data] = explode("\n", $chunk, 2);

        expect($event)->toStartWith('event: ')->and($data)->toStartWith('data: ');

        $frames[] = [
            substr($event, 7),
            json_decode(substr($data, 6), true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    return $frames;
}

/**
 * @return Collection<int, array{string, array<string, mixed>}>
 */
function sseFramesOfType(string $body, string $type): Collection
{
    return collect(sseParsed($body))->filter(fn (array $frame): bool => $frame[0] === $type)->values();
}

function sseAnswerText(string $body): string
{
    return sseFramesOfType($body, 'text')->map(fn (array $frame): string => $frame[1]['delta'])->implode('');
}

/**
 * Every tool result the run actually produced, keyed by tool.
 *
 * @return array<string, string>
 */
function toolResultsOf(StreamableAgentResponse $stream): array
{
    return $stream->events
        ->filter(fn (object $event): bool => $event instanceof ToolResult)
        ->mapWithKeys(fn (ToolResult $event): array => [
            $event->toolResult->name => (string) $event->toolResult->result,
        ])
        ->all();
}

/**
 * One embedded passage from the books, with a citation a guest could check.
 */
function sseBookCorpus(): void
{
    $book = Book::factory()->create([
        'slug' => 'old-waldorf-bar-days-1931',
        'title' => 'Old Waldorf Bar Days',
        'author' => 'Albert Stevens Crockett',
        'year' => 1931,
    ]);

    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));

    BookChunk::factory()->for($book)->embedded(unitVector(1))->create([
        'section_title' => 'Concerning the Curriculum',
        'heading' => 'BLUE LADY',
        'headings' => ['BLUE LADY'],
        'text' => "BLUE LADY 1/2 Blue Curaçao.\n1/4 Booth's Gin.\nShake and strain.",
        'page_from' => 119,
        'page_to' => 119,
        'printed_page_from' => '107',
        'printed_page_to' => '107',
    ]);
}

/**
 * The house's own pages, plus a page Sasha can search.
 *
 * The import runs first and clears out any chunk the export does not name, so
 * the searchable page is written after it and under a slug the fixture does
 * not use.
 */
function sseHouseCorpus(): void
{
    importHouse();

    Embeddings::fake(fn ($prompt): array => array_map(fn (): array => unitVector(1), $prompt->inputs));

    HouseChunk::factory()->embedded(unitVector(1))->create([
        'source_type' => HouseSourceType::Cocktail,
        'source_slug' => 'blackberry-nocturne',
        'title' => 'Blackberry Nocturne',
        'subtitle' => 'Our own house original',
        'text' => "Blackberry Nocturne\n\n2oz Rye Whiskey\n.5oz Blackberry Syrup\nStirred, up.",
        'keywords' => ['Rye Whiskey', 'Blackberry Syrup'],
        'url' => 'https://thekrausshaus.com/cocktails/blackberry-nocturne',
    ]);
}

/**
 * The terminal's test, over the wire. The fake gateway emits one delta per
 * word, so an answer that arrives as a single frame is an answer that was
 * collected before it was sent -- which is the whole thing SSE is here to
 * avoid.
 */
it('frames a real answer delta by delta', function (): void {
    EddieAgent::fake(["That one's out of the Savoy, friend."]);

    [$body] = sseRun('eddie', 'what goes in a Blue Lady?');

    expect(sseAnswerText($body))->toBe("That one's out of the Savoy, friend.")
        ->and(sseFramesOfType($body, 'text')->count())->toBeGreaterThan(1);

    EddieAgent::assertPrompted('what goes in a Blue Lady?');
});

/**
 * The in-character note a guest reads while a bartender is busy. It is the
 * label rather than the tool's name, and it is the only thing a tool call
 * puts on the wire.
 */
it('frames the tool call as the note the guest is meant to read', function (): void {
    sseBookCorpus();

    EddieAgent::fake([
        new ToolCallData('c1', 'SearchTheBooks', ['query' => 'blue lady']),
        'Gin and blue curaçao, friend.',
    ]);

    [$body] = sseRun('eddie', 'what goes in a Blue Lady?');

    expect(sseFramesOfType($body, 'tool')->all())->toBe([
        ['tool', ['label' => 'reaching for the books']],
    ]);
});

/**
 * The one tool result a guest may see, and the reason AnswerStream takes a
 * fourth callback at all. Nothing between the two bartenders is stubbed here:
 * Eddie's fake calls the real AskSasha, which runs through the real desk to a
 * faked Sasha, and a real ToolResult comes back through the real AnswerStream.
 */
it('frames a consult under the name of whoever said it', function (): void {
    SashaAgent::fake(["Rye and blackberry, stirred. It's the Midnight Rambler."]);
    EddieAgent::fake([
        new ToolCallData('c1', 'AskSasha', ['question' => 'What would a modern bar do with rye?']),
        "That's Sasha's, over at the house.",
    ]);

    [$body] = sseRun('eddie', 'what would a modern bartender do?');

    expect(sseFramesOfType($body, 'consult')->all())->toBe([
        ['consult', [
            'bartender' => 'Sasha',
            'answer' => "Rye and blackberry, stirred. It's the Midnight Rambler.",
        ]],
    ])->and(sseAnswerText($body))->toBe("That's Sasha's, over at the house.");
});

it('frames a consult in the other direction too', function (): void {
    EddieAgent::fake(["That one's out of the Savoy, 1930, page 42."]);
    SashaAgent::fake([
        new ToolCallData('c1', 'AskEddie', ['question' => 'Where does the Sazerac come from?']),
        'Eddie has it in the Savoy. We do not pour it here.',
    ]);

    [$body] = sseRun('sasha', 'where is the sazerac from?');

    expect(sseFramesOfType($body, 'consult')->first())
        ->toBe(['consult', ['bartender' => 'Eddie', 'answer' => "That one's out of the Savoy, 1930, page 42."]]);
});

/**
 * The assertion this class exists for, and the reason the package's own
 * toResponse() and Vercel-protocol streamer are not used: both write the raw
 * event stream, and the raw event stream carries the eight-key passage payload
 * and the six-key survey payload.
 *
 * Nothing is mocked away. The tools run for real against real rows, and the
 * run's own events are read back to prove the payload was genuinely there --
 * so if retrieval ever returns nothing, this test fails rather than passing
 * for the wrong reason.
 */
it('never puts a books payload on the wire', function (): void {
    sseBookCorpus();
    tallied();

    EddieAgent::fake([
        new ToolCallData('c1', 'SearchTheBooks', ['query' => 'blue lady']),
        new ToolCallData('c2', 'SurveyTheBooks', []),
        'Gin and blue curaçao, friend, and it keeps turning up.',
    ]);

    [$body, $stream] = sseRun('eddie', 'what goes in a Blue Lady, and how often is it printed?');

    $results = toolResultsOf($stream);

    // What the model was handed, which is what must not reach the browser.
    expect(array_keys($results))->toEqualCanonicalizing(['SearchTheBooks', 'SurveyTheBooks']);

    $leaks = [
        "Booth's Gin", 'Shake and strain', 'Albert Stevens Crockett', 'Old Waldorf Bar Days',
        '"book_title"', '"citation"', 'PDF p. 119', 'Passages from the books',
        '"also_printed_as"', '"mentions"', 'Tallied across',
    ];

    $handedToTheModel = implode("\n", $results);

    foreach ($leaks as $leak) {
        expect($handedToTheModel)->toContain($leak);
        expect($body)->not->toContain($leak);
    }

    // And the tools really did run inside this stream, rather than the frames
    // being thin because nothing happened.
    expect($body)->toContain('"label":"reaching for the books"')
        ->and($body)->toContain('"label":"counting what\'s on the shelf"')
        ->and(sseAnswerText($body))->toBe('Gin and blue curaçao, friend, and it keeps turning up.');
});

it('never puts a house payload on the wire', function (): void {
    sseHouseCorpus();

    SashaAgent::fake([
        new ToolCallData('c1', 'SearchTheHouse', ['query' => 'blackberry nocturne']),
        new ToolCallData('c2', 'BrowseTheMenus', []),
        'We pour something close to that, and I can talk you through it.',
    ]);

    [$body, $stream] = sseRun('sasha', 'what have you got with blackberry?');

    $results = toolResultsOf($stream);

    expect(array_keys($results))->toEqualCanonicalizing(['SearchTheHouse', 'BrowseTheMenus']);

    $leaks = [
        'Blackberry Syrup', 'Blackberry Nocturne', 'thekrausshaus.com', 'Pages from the house',
        '"kind"', '"citation"', '"build"', '"served"', 'Midnight Rambler', 'The house pours',
    ];

    $handedToTheModel = implode("\n", $results);

    foreach ($leaks as $leak) {
        expect($handedToTheModel)->toContain($leak);
        expect($body)->not->toContain($leak);
    }

    expect($body)->toContain('"label":"checking the house pages"')
        ->and($body)->toContain('"label":"running an eye down the menus"')
        ->and(sseAnswerText($body))->toBe('We pour something close to that, and I can talk you through it.');
});

/**
 * A guest's question is not a place to put a newline, but a model's answer is,
 * and a bare newline inside a `data:` field ends the frame early and leaves
 * the rest of the answer on the wire as nothing at all.
 */
it('survives an answer with paragraphs in it', function (): void {
    EddieAgent::fake(["Two parts gin.\n\nOne part curaçao."]);

    [$body] = sseRun('eddie', 'how is it built?');

    expect(sseAnswerText($body))->toContain("Two parts gin.\n\nOne part curaçao.");

    foreach (explode("\n", $body) as $line) {
        expect($line === '' || str_starts_with($line, 'event: ') || str_starts_with($line, 'data: '))->toBeTrue(
            "A frame line was neither a field nor blank: {$line}",
        );
    }
});
