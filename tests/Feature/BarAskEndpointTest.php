<?php

use App\Agents\EddieAgent;
use App\Agents\SashaAgent;
use App\Http\Middleware\VerifyBarKey;
use App\Models\Book;
use App\Models\BookChunk;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;

/**
 * The bar, over the wire.
 *
 * SseAnswerStreamTest pins the framing and WebTabKeeperTest pins the tab; what
 * is pinned here is the route -- that the lock is in front of it, that a bad
 * request never reaches a model, that the id handed out in the opening frame
 * is the id a follow-up can be asked on, and that the payload rule survives
 * the whole trip rather than only the part of it a unit test can see.
 */
beforeEach(function (): void {
    config()->set('bar.api.keys', ['the-house-key']);

    app()->bind(Reranker::class, fn (): Reranker => new NullReranker);
});

/**
 * Ask the way the Krauss Haus server will, holding the key unless told not to.
 */
function webAsk(array $payload, ?string $key = 'the-house-key'): TestResponse
{
    return test()->postJson('/api/ask', $payload, $key === null ? [] : [VerifyBarKey::HEADER => $key]);
}

/**
 * Read the body back as a client would: frames split on the blank line, each
 * one an event name and a JSON object.
 *
 * @return list<array{string, array<string, mixed>}>
 */
function webFrames(TestResponse $response): array
{
    $frames = [];

    foreach (explode("\n\n", $response->streamedContent()) as $chunk) {
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
 * @return Collection<int, string>
 */
function webEvents(TestResponse $response): Collection
{
    return collect(webFrames($response))->map(fn (array $frame): string => $frame[0]);
}

/**
 * The answer as the guest reads it, reassembled from its deltas.
 */
function webAnswer(TestResponse $response): string
{
    return collect(webFrames($response))
        ->filter(fn (array $frame): bool => $frame[0] === 'text')
        ->map(fn (array $frame): string => $frame[1]['delta'])
        ->implode('');
}

/**
 * The conversation the opening frame handed back.
 */
function webConversationId(TestResponse $response): string
{
    $frames = webFrames($response);

    expect($frames[0][0])->toBe('meta');

    return (string) $frames[0][1]['conversation_id'];
}

/**
 * Everything the tools actually handed the model during a run, read back off
 * the stored turns.
 *
 * The tool results are written down because the bartender remembers, which
 * makes the conversation rows the one place a test on this side of the wire
 * can see what the browser was protected from. Without it a leak test passes
 * just as happily when retrieval returned nothing at all.
 */
function webToolResults(): string
{
    return DB::table('agent_conversation_messages')
        ->pluck('tool_results')
        ->flatMap(fn (?string $json): array => (array) json_decode((string) $json, true))
        ->map(fn (array $result): string => is_string($result['result'] ?? null)
            ? $result['result']
            : (string) json_encode($result['result'] ?? null))
        ->implode("\n");
}

/**
 * One embedded passage from the books, with a citation a guest could check.
 */
function webBookCorpus(): void
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
 * The lock is in front of the expensive door, not only the free one.
 *
 * BarApiKeyTest knocks on /api/bartenders because it is cheap; this is the
 * route where being wrong costs money, so it is asserted on its own rather
 * than inferred from the group in routes/api.php.
 */
it('refuses a question from a caller without the key, and asks nobody anything', function (): void {
    EddieAgent::fake(['Never said.']);

    webAsk(['question' => 'what goes in a Blue Lady?', 'bartender' => 'eddie'], key: null)
        ->assertUnauthorized();

    webAsk(['question' => 'what goes in a Blue Lady?', 'bartender' => 'eddie'], key: 'not-the-house-key')
        ->assertUnauthorized();

    EddieAgent::assertNeverPrompted();

    // Nor was a tab opened for somebody who never got in.
    expect(Conversation::count())->toBe(0);
});

/**
 * Validation is in front of the model for the same reason the key is: every
 * question is billed, so a request that cannot be answered must cost nothing.
 * It is answered in JSON rather than as a stream carrying an error frame --
 * the stream starts when there is an answer coming.
 */
it('turns a request it cannot answer away as JSON, before anything is spent', function (array $payload): void {
    EddieAgent::fake(['Never said.']);
    SashaAgent::fake(['Never said.']);

    webAsk($payload)
        ->assertUnprocessable()
        ->assertHeader('content-type', 'application/json');

    EddieAgent::assertNeverPrompted();
    SashaAgent::assertNeverPrompted();

    expect(Conversation::count())->toBe(0);
})->with([
    // Each payload is wrapped in its own array: a dataset entry is an argument
    // list, so an unwrapped one arrives as several arguments rather than one.
    'no question' => [['bartender' => 'eddie']],
    'an empty question' => [['question' => '', 'bartender' => 'eddie']],
    'a question of nothing but spaces' => [['question' => '     ', 'bartender' => 'eddie']],
    'a question that is not a string' => [['question' => ['a', 'b'], 'bartender' => 'eddie']],
    'a question longer than the bar will take' => [['question' => str_repeat('gin ', 1000), 'bartender' => 'eddie']],
    'nobody named' => [['question' => 'what goes in a Blue Lady?']],
    'nobody who works here' => [['question' => 'what goes in a Blue Lady?', 'bartender' => 'gus']],
    'a bartender that is not a string' => [['question' => 'anything', 'bartender' => ['eddie']]],
]);

/**
 * The shape the Krauss Haus site is written against: the id first, the answer
 * in pieces, and one thing to wait for at the end.
 */
it('opens with the conversation id, streams the answer and closes with done', function (): void {
    EddieAgent::fake(["That one's out of the Savoy, friend."]);

    $response = webAsk(['question' => 'what goes in a Blue Lady?', 'bartender' => 'eddie'])->assertOk();

    expect($response->headers->get('content-type'))->toStartWith('text/event-stream')
        ->and((string) $response->headers->get('cache-control'))->toContain('no-cache');

    $frames = webFrames($response);

    expect($frames[0][0])->toBe('meta')
        ->and(array_keys($frames[0][1]))->toBe(['conversation_id'])
        ->and($frames[0][1]['conversation_id'])->toBe((string) Conversation::sole()->getKey())
        ->and(end($frames))->toBe(['done', []])
        // Nothing but the answer in between: a frame type invented here would
        // be one the terminal never sees.
        ->and(webEvents($response)->unique()->values()->all())->toBe(['meta', 'text', 'done'])
        ->and(webAnswer($response))->toBe("That one's out of the Savoy, friend.");

    EddieAgent::assertPrompted('what goes in a Blue Lady?');
});

/**
 * The reason this is SSE at all. The fake gateway emits one delta per word, so
 * an answer arriving as a single frame is an answer that was collected before
 * it was sent -- which is the terminal's "writes the answer in pieces rather
 * than in a block", one layer out.
 */
it('sends the answer in pieces rather than in one lump', function (): void {
    EddieAgent::fake(["That one's out of the Savoy, friend."]);

    $response = webAsk(['question' => 'what goes in a Blue Lady?', 'bartender' => 'eddie']);

    expect(webEvents($response)->filter(fn (string $event): bool => $event === 'text')->count())
        ->toBeGreaterThan(1);
});

it('asks whoever the request named, and nobody else', function (): void {
    EddieAgent::fake(['Never said.']);
    SashaAgent::fake(['That one I can do — it is on the spring menu.']);

    $response = webAsk(['question' => 'something bright', 'bartender' => 'sasha'])->assertOk();

    expect(webAnswer($response))->toBe('That one I can do — it is on the spring menu.');

    SashaAgent::assertPrompted('something bright');
    EddieAgent::assertNeverPrompted();
});

/**
 * The in-character note a guest reads while a bartender is busy -- the label,
 * never the tool's name, and never what the tool came back with.
 */
it('frames a tool call as the note the guest is meant to read', function (): void {
    webBookCorpus();

    EddieAgent::fake([
        new ToolCallData('c1', 'SearchTheBooks', ['query' => 'blue lady']),
        'Gin and blue curaçao, friend.',
    ]);

    $response = webAsk(['question' => 'what goes in a Blue Lady?', 'bartender' => 'eddie'])->assertOk();

    expect(collect(webFrames($response))->filter(fn (array $frame): bool => $frame[0] === 'tool')->values()->all())
        ->toBe([['tool', ['label' => 'reaching for the books']]]);
});

/**
 * The payload rule, end to end and against the real thing.
 *
 * SseAnswerStreamTest proves the framing drops a ToolResult; this proves that
 * nothing between the route and the socket puts it back -- not the response
 * factory, not the exception handler, not a header. Nothing is mocked but the
 * model: the tools run against real rows, and what they handed the model is
 * read back off the stored turns, so a run where retrieval quietly returned
 * nothing fails here instead of passing for the wrong reason.
 */
it('never puts a retrieval payload on the wire', function (): void {
    webBookCorpus();
    tallied();

    EddieAgent::fake([
        new ToolCallData('c1', 'SearchTheBooks', ['query' => 'blue lady']),
        new ToolCallData('c2', 'SurveyTheBooks', []),
        'Gin and blue curaçao, friend, and it keeps turning up.',
    ]);

    $response = webAsk([
        'question' => 'what goes in a Blue Lady, and how often is it printed?',
        'bartender' => 'eddie',
    ])->assertOk();

    $body = $response->streamedContent();
    $handedToTheModel = webToolResults();

    $leaks = [
        "Booth's Gin", 'Shake and strain', 'Albert Stevens Crockett', 'Old Waldorf Bar Days',
        'book_title', 'citation', 'PDF p. 119', 'Passages from the books',
        'also_printed_as', 'mentions', 'Tallied across',
    ];

    foreach ($leaks as $leak) {
        expect($handedToTheModel)->toContain($leak);
        expect($body)->not->toContain($leak);
    }

    // And the tools really did run inside this request, rather than the body
    // being thin because nothing happened.
    expect($body)->toContain('"label":"reaching for the books"')
        ->and($body)->toContain('"label":"counting what\'s on the shelf"')
        ->and(webAnswer($response))->toBe('Gin and blue curaçao, friend, and it keeps turning up.');
});

/**
 * The feature the conversation id exists for. Send it back and the bartender
 * remembers; without it "make it lighter" is asked into a room that has never
 * met the guest.
 */
it('carries the conversation on when its id is sent back', function (): void {
    EddieAgent::fake(['Rye, absinthe and Peychauds.', 'Cognac instead of the rye, then.']);

    $first = webAsk(['question' => 'what goes in a sazerac?', 'bartender' => 'eddie'])->assertOk();
    $conversationId = webConversationId($first);

    $second = webAsk([
        'question' => 'make it lighter',
        'bartender' => 'eddie',
        'conversation_id' => $conversationId,
    ])->assertOk();

    expect(webConversationId($second))->toBe($conversationId)
        ->and(Conversation::count())->toBe(1)
        ->and(webAnswer($second))->toBe('Cognac instead of the rye, then.');

    $turns = DB::table('agent_conversation_messages')->orderBy('id')->get();

    expect($turns->pluck('role')->all())->toBe(['user', 'assistant', 'user', 'assistant'])
        ->and($turns->pluck('conversation_id')->unique())->toHaveCount(1);

    // Not merely written down: read back. This is the package's own read path,
    // which is what the second turn was answered with.
    $remembered = collect(app(EddieAgent::class)->continue($conversationId)->messages())
        ->map(fn (object $message): string => (string) $message->content)
        ->implode("\n");

    expect($remembered)->toContain('what goes in a sazerac?')
        ->and($remembered)->toContain('Rye, absinthe and Peychauds.');
});

/**
 * Two browsers are two guests. Nothing a caller sends names a conversation, so
 * there is no value either of them could put in a request that lands them in
 * the other's.
 */
it('gives two callers two conversations when neither sends an id', function (): void {
    EddieAgent::fake(['Rye, absinthe and Peychauds.', 'Rye, absinthe and Peychauds.']);

    $hers = webConversationId(webAsk(['question' => 'what goes in a sazerac?', 'bartender' => 'eddie']));
    $his = webConversationId(webAsk(['question' => 'what goes in a sazerac?', 'bartender' => 'eddie']));

    expect($his)->not->toBe($hers)
        ->and(Conversation::count())->toBe(2);
});

/**
 * One tab per bartender, never one between them, asserted where a caller could
 * actually try it. laravel/ai replays a stored assistant turn as the *current*
 * agent's own prior words, so an id Eddie issued, accepted by Sasha, would
 * hand her his book-cited drinks as her own memory -- the one thing the
 * consult was designed to make impossible.
 */
it('will not let an id issued by eddie be carried into sasha', function (): void {
    EddieAgent::fake(["That one's out of the Savoy, friend."]);
    SashaAgent::fake(['Nothing like that on our menus.']);

    $his = webConversationId(webAsk(['question' => 'where is the sazerac from?', 'bartender' => 'eddie']));

    $hers = webConversationId(webAsk([
        'question' => 'something bright',
        'bartender' => 'sasha',
        'conversation_id' => $his,
    ]));

    expect($hers)->not->toBe($his)
        ->and((string) Conversation::find($hers)->participant_type)->toEndWith(':sasha');

    $remembered = collect(app(SashaAgent::class)->continue($hers)->messages())
        ->map(fn (object $message): string => (string) $message->content)
        ->implode("\n");

    expect($remembered)->not->toContain('Savoy');
});

/**
 * An id that is not one of ours is not an error, because an error is an
 * answer: "that id is real but it is Eddie's" and "that id expired" are both
 * facts about a conversation that is not the caller's. They all get the same
 * thing -- a new conversation, and an id in the opening frame.
 */
it('says nothing at all about an id it will not accept', function (string $sent): void {
    EddieAgent::fake(['Rye, absinthe and Peychauds.']);

    $response = webAsk([
        'question' => 'make it lighter',
        'bartender' => 'eddie',
        'conversation_id' => $sent,
    ])->assertOk();

    expect(Conversation::find(webConversationId($response)))->not->toBeNull()
        ->and(webEvents($response))->not->toContain('error');
})->with([
    'never existed' => fn (): string => (string) Str::uuid(),
    'not an id at all' => "'; drop table agent_conversations; --",
]);

/**
 * A stream can fail half a sentence in, where the old blocking path could only
 * fail before a word had been sent -- and by then a 200 is already on the wire,
 * so an `error` frame is the only status this endpoint has left.
 *
 * What goes out is the bartender's name and a sentence. The terminal prints
 * the provider's own message after it, because the only person reading a
 * terminal is the one running the application; this end is read by a guest,
 * and a provider exception can carry a host, a model, a key fragment or a
 * stack of internals. The real thing is reported, where an operator can find
 * it.
 */
it('answers a failed stream with an error frame rather than a stack trace', function (): void {
    Exceptions::fake();

    EddieAgent::fake(fn (): never => throw new RuntimeException('Connection refused by 10.0.0.4 using sk-live-1234'));

    $response = webAsk(['question' => 'anything', 'bartender' => 'eddie'])->assertOk();

    $frames = webFrames($response);
    $body = $response->streamedContent();

    expect(webEvents($response)->all())->toBe(['meta', 'error', 'done'])
        ->and($frames[1][1]['message'])->toContain('Eddie')
        // Still handed an id, so a caller keeps the tab they were given.
        ->and(Conversation::find(webConversationId($response)))->not->toBeNull()
        ->and($body)->not->toContain('Connection refused')
        ->and($body)->not->toContain('10.0.0.4')
        ->and($body)->not->toContain('sk-live-1234')
        ->and($body)->not->toContain('RuntimeException')
        ->and($body)->not->toContain('BarAskController');

    Exceptions::assertReported(RuntimeException::class);
});

/**
 * The consult limit is per answer. A CLI run is a process and a process is an
 * answer, but the desk is a singleton and this application is not going to be
 * restarted between questions -- so a second request must find the desk as
 * empty as the first did, rather than inheriting what the first one spent.
 *
 * With the allowance set to one, a desk that was never reset refuses the
 * second consult -- and refuses it *successfully*, as a sentence Eddie reads
 * out. So the frame still arrives and only its text gives the failure away,
 * which is why this asserts what Sasha said rather than that she was asked.
 */
it('gives every request its own allowance of consults', function (): void {
    config()->set('bar.consults.limit', 1);

    $consultsIn = function (): array {
        SashaAgent::fake(['Rye and blackberry, stirred.']);
        EddieAgent::fake([
            new ToolCallData('c1', 'AskSasha', ['question' => 'What would a modern bar do with rye?']),
            "That's Sasha's, over at the house.",
        ]);

        $response = webAsk(['question' => 'what would a modern bartender do?', 'bartender' => 'eddie'])->assertOk();

        // Read before the next request is made. A streamed body is produced
        // when it is read, not when it is returned, so two responses collected
        // and then read would both run against the second one's fakes.
        return collect(webFrames($response))
            ->filter(fn (array $frame): bool => $frame[0] === 'consult')
            ->values()
            ->all();
    };

    $expected = [['consult', ['bartender' => 'Sasha', 'answer' => 'Rye and blackberry, stirred.']]];

    expect($consultsIn())->toBe($expected)
        ->and($consultsIn())->toBe($expected);
});
