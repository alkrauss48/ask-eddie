<?php

use App\Ai\Streaming\SseAnswerStream;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

/**
 * @param  list<StreamEvent>  $events
 */
function sseResponse(array $events): StreamableAgentResponse
{
    return new StreamableAgentResponse(
        'inv-1',
        function () use ($events): Generator {
            yield from $events;
        },
        new Meta('fake', 'fake-model'),
    );
}

function sseTextDelta(string $text, string $messageId = 'msg-1'): TextDelta
{
    return new TextDelta(uniqid(), $messageId, $text, 0);
}

/**
 * The frames a run produces, as the browser would receive them.
 *
 * @param  list<StreamEvent>  $events
 * @return list<string>
 */
function sseFrames(array $events): array
{
    $sse = new SseAnswerStream(sseResponse($events), (array) config('bar.labels'));

    return iterator_to_array($sse->stream(), false);
}

/**
 * @param  list<StreamEvent>  $events
 */
function sseBody(array $events): string
{
    return implode('', sseFrames($events));
}

it('frames each delta as it arrives, in order', function (): void {
    $frames = sseFrames([
        sseTextDelta('That'), sseTextDelta(" one's"), sseTextDelta(' out of the Savoy.'),
    ]);

    expect($frames)->toBe([
        "event: text\ndata: {\"delta\":\"That\"}\n\n",
        "event: text\ndata: {\"delta\":\" one's\"}\n\n",
        "event: text\ndata: {\"delta\":\" out of the Savoy.\"}\n\n",
    ]);
});

/**
 * Not a formality: a `data:` field ends at the first newline, so a delta
 * carrying one would end its own frame and leave the rest of the answer as
 * unprefixed garbage. JSON-encoding the delta is what prevents that, and the
 * step break AnswerStream emits between two messages is exactly such a delta.
 */
it('keeps a delta that contains newlines inside one data line', function (): void {
    $body = sseBody([
        sseTextDelta('Let me look.', 'msg-1'),
        sseTextDelta('Here it is.', 'msg-2'),
    ]);

    expect($body)->toContain('data: {"delta":"\n\n"}');

    // Every line is a field or the blank line that ends a frame, and nothing
    // else -- which is the whole of the SSE wire format.
    foreach (explode("\n", $body) as $line) {
        expect($line === '' || str_starts_with($line, 'event: ') || str_starts_with($line, 'data: '))->toBeTrue(
            "A frame line was neither a field nor blank: {$line}",
        );
    }
});

it('frames a tool call under its in-character label', function (): void {
    $frames = sseFrames([
        new ToolCall('t1', new ToolCallData('c1', 'BrowseTheMenus', []), 0),
    ]);

    expect($frames)->toBe(["event: tool\ndata: {\"label\":\"running an eye down the menus\"}\n\n"]);
});

it('frames a consult under the name of whoever said it', function (): void {
    $said = 'Rye and blackberry, stirred, with a long lemon twist.';

    $frames = sseFrames([
        new ToolResult('r1', new ToolResultData('c1', 'AskSasha', [], $said), true, null, 0),
    ]);

    expect($frames)->toBe([
        "event: consult\ndata: {\"bartender\":\"Sasha\",\"answer\":\"{$said}\"}\n\n",
    ]);
});

it('names the other bartender when the consult goes the other way', function (): void {
    $frames = sseFrames([
        new ToolResult('r1', new ToolResultData('c1', 'AskEddie', [], 'Out of the Savoy, 1930.'), true, null, 0),
    ]);

    expect($frames[0])->toContain('"bartender":"Eddie"');
});

it('frames a provider error rather than going quiet', function (): void {
    $frames = sseFrames([
        new Error('e1', 'error', 'The provider hung up.', false, 0),
    ]);

    expect($frames)->toBe(["event: error\ndata: {\"message\":\"The provider hung up.\"}\n\n"]);
});

/**
 * The rule this class is wrapped around. AnswerStream drops every tool result
 * but a consult's, and framing must not have re-decided that -- the real proof
 * is in tests/Feature, against payloads the tools actually produced, but the
 * synthetic case pins the shape.
 */
it('frames nothing at all for a retrieval tool result', function (): void {
    $passage = '[{"book_title":"Old Waldorf Bar Days","text":"BLUE LADY 1/4 Booth\'s Gin."}]';

    $frames = sseFrames([
        new ToolCall('t1', new ToolCallData('c1', 'SearchTheBooks', []), 0),
        new ToolResult('r1', new ToolResultData('c1', 'SearchTheBooks', [], $passage), true, null, 0),
        sseTextDelta('Gin and curaçao, friend.'),
    ]);

    expect($frames)->toBe([
        "event: tool\ndata: {\"label\":\"reaching for the books\"}\n\n",
        "event: text\ndata: {\"delta\":\"Gin and curaçao, friend.\"}\n\n",
    ])->and(implode('', $frames))->not->toContain('Booth');
});

/**
 * meta and done are the caller's, because only it knows the conversation id
 * and only it knows the connection survived to the end. They are built through
 * the same helper so the protocol is written down once.
 */
it('builds the frames the caller wraps the answer in', function (): void {
    expect(SseAnswerStream::frame('meta', ['conversation_id' => '9f3c']))
        ->toBe("event: meta\ndata: {\"conversation_id\":\"9f3c\"}\n\n")
        // An empty payload is an object, not an array: a client parsing every
        // frame the same way should not have to special-case `[]`.
        ->and(SseAnswerStream::frame('done'))->toBe("event: done\ndata: {}\n\n")
        ->and(SseAnswerStream::frame('done', []))->toBe("event: done\ndata: {}\n\n");
});

it('leaves a slash and an accent as themselves', function (): void {
    $frame = SseAnswerStream::frame('text', ['delta' => 'Curaçao, https://thekrausshaus.com']);

    expect($frame)->toContain('Curaçao')
        ->and($frame)->toContain('https://thekrausshaus.com');
});

/**
 * The reason any of this is a generator. A browser is meant to see the first
 * word before the last one has been written, so a frame has to be yieldable
 * while the provider is still talking -- which is what the fiber buys and what
 * collecting the answer first would throw away.
 */
it('yields a frame before the rest of the answer has arrived', function (): void {
    $produced = [];

    $response = new StreamableAgentResponse('inv-1', function () use (&$produced): Generator {
        foreach (['One', ' two', ' three'] as $word) {
            $produced[] = $word;

            yield sseTextDelta($word);
        }
    }, new Meta('fake', 'fake-model'));

    $frames = (new SseAnswerStream($response))->stream();

    expect($frames->current())->toBe("event: text\ndata: {\"delta\":\"One\"}\n\n")
        ->and($produced)->toBe(['One']);

    $frames->next();

    expect($produced)->toBe(['One', ' two']);
});

it('frames nothing when the bartender said nothing', function (): void {
    expect(sseFrames([]))->toBe([]);
});

/**
 * An unlabelled tool shows its own name here for the same reason it does in
 * the terminal: a silent frame would hide the call, and a pause a guest cannot
 * account for reads as a hang.
 */
it('falls back to the tool name when nobody labelled it', function (): void {
    $frames = sseFrames([
        new ToolCall('t1', new ToolCallData('c1', 'ANewToolNobodyThoughtAbout', []), 0),
    ]);

    expect($frames[0])->toContain('"label":"ANewToolNobodyThoughtAbout"');
});
