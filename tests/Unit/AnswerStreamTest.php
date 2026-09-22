<?php

use App\Ai\Streaming\AnswerStream;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

/**
 * @param  list<StreamEvent>  $events
 */
function eddieStream(array $events): StreamableAgentResponse
{
    return new StreamableAgentResponse(
        'inv-1',
        function () use ($events): Generator {
            yield from $events;
        },
        new Meta('fake', 'fake-model'),
    );
}

function textDelta(string $text, string $messageId = 'msg-1'): TextDelta
{
    return new TextDelta(uniqid(), $messageId, $text, 0);
}

/**
 * @return array{string, list<string>, list<string>, list<array{string, string}>}
 */
function collectStream(array $events): array
{
    $text = '';
    $tools = [];
    $errors = [];
    $consults = [];

    // The label map is configuration rather than a const on the stream, so
    // that the two bartenders' tools can be named in one place. Passed here the
    // way the command passes it.
    (new AnswerStream(eddieStream($events), config('bar.labels')))->each(
        onText: function (string $delta) use (&$text): void {
            $text .= $delta;
        },
        onTool: function (string $label) use (&$tools): void {
            $tools[] = $label;
        },
        onError: function (string $message) use (&$errors): void {
            $errors[] = $message;
        },
        onConsult: function (string $tool, string $answer) use (&$consults): void {
            $consults[] = [$tool, $answer];
        },
    );

    return [$text, $tools, $errors, $consults];
}

it('hands the deltas on in order', function (): void {
    [$text] = collectStream([
        new TextStart('a', 'msg-1', 0),
        textDelta('That'), textDelta(" one's"), textDelta(' out of the Savoy.'),
        new TextEnd('b', 'msg-1', 0),
    ]);

    expect($text)->toBe("That one's out of the Savoy.");
});

/**
 * The blocking path got this free from TextDelta::combine(), which groups by
 * message id and joins the groups with a blank line. Each step is a
 * self-contained utterance -- usually narration either side of a tool call --
 * so without the break two steps run together mid-sentence.
 */
it('separates one step from the next with a blank line', function (): void {
    [$text] = collectStream([
        textDelta('Let me look.', 'msg-1'),
        textDelta('Here it is.', 'msg-2'),
    ]);

    expect($text)->toBe("Let me look.\n\nHere it is.");
});

it('does not open with a blank line when a step says nothing', function (): void {
    [$text] = collectStream([
        textDelta('Let me look.', 'msg-1'),
        textDelta('   ', 'msg-2'),
        textDelta('Here it is.', 'msg-3'),
    ]);

    expect($text)->toBe("Let me look.\n\nHere it is.");
});

/**
 * trim() could tidy a finished answer; nothing can be taken off the front of a
 * stream once it has been printed.
 */
it('swallows the whitespace a model opens with', function (): void {
    [$text] = collectStream([textDelta("\n  "), textDelta('Evening, friend.')]);

    expect($text)->toBe('Evening, friend.');
});

it('names the tools the way Eddie would', function (): void {
    [, $tools] = collectStream([
        new ToolCall('t1', new ToolCallData('c1', 'SearchTheBooks', ['query' => 'blue lady']), 0),
        new ToolCall('t2', new ToolCallData('c2', 'SurveyTheBooks', []), 0),
    ]);

    expect($tools)->toBe(['reaching for the books', "counting what's on the shelf"]);
});

it('falls back to the tool name it was given', function (): void {
    [, $tools] = collectStream([
        new ToolCall('t1', new ToolCallData('c1', 'SomethingElse', []), 0),
    ]);

    expect($tools)->toBe(['SomethingElse']);
});

/**
 * The whole reason this class exists rather than a foreach at each consumer. A
 * ToolResult carries the eight-key passage payload that .ai/rules/retrieval.md
 * keeps out of the model's context; a terminal and an SSE connection are the
 * same leak in two costumes.
 */
it('never lets a tool result out', function (): void {
    $passage = 'BLUE LADY 1/2 Blue Curaçao. 1/4 Booth\'s Gin.';

    [$text, $tools, $errors] = collectStream([
        new ToolCall('t1', new ToolCallData('c1', 'SearchTheBooks', []), 0),
        new ToolResult('r1', new ToolResultData('c1', 'SearchTheBooks', [], $passage), true, null, 0),
        textDelta('That one is gin and curaçao.'),
    ]);

    expect($text)->toBe('That one is gin and curaçao.')
        ->and($text)->not->toContain('Booth')
        ->and($tools)->toBe(['reaching for the books'])
        ->and($errors)->toBeEmpty();
});

it('surfaces an error rather than going quiet', function (): void {
    [, , $errors] = collectStream([
        new Error('e1', 'error', 'The provider hung up.', false, 0),
    ]);

    expect($errors)->toBe(['The provider hung up.']);
});

it('works without the optional handlers', function (): void {
    $text = '';

    (new AnswerStream(eddieStream([
        new ToolCall('t1', new ToolCallData('c1', 'SearchTheBooks', []), 0),
        new Error('e1', 'error', 'Ignored.', true, 0),
        textDelta('Evening.'),
    ])))->each(function (string $delta) use (&$text): void {
        $text .= $delta;
    });

    expect($text)->toBe('Evening.');
});

/**
 * The one exception to the rule above, and it is an exception to *what a tool
 * result is* rather than to the rule. A consult's result is not a payload; it
 * is prose one bartender wrote for a human, and the guest is meant to read it
 * attributed to whoever said it.
 */
it('lets a consult through, named by the tool that made it', function (): void {
    $said = 'Rye and blackberry, stirred, with a long lemon twist.';

    [$text, $tools, , $consults] = collectStream([
        new ToolCall('t1', new ToolCallData('c1', 'AskSasha', ['question' => 'something bright?']), 0),
        new ToolResult('r1', new ToolResultData('c1', 'AskSasha', [], $said), true, null, 0),
        textDelta("That's Sasha's, over at the house."),
    ]);

    expect($consults)->toBe([['AskSasha', $said]])
        ->and($tools)->toBe(['calling Sasha over'])
        // The consult is handed to its own callback, never spliced into the
        // bartender's own sentence.
        ->and($text)->toBe("That's Sasha's, over at the house.");
});

it('lets the consult through in the other direction too', function (): void {
    [, , , $consults] = collectStream([
        new ToolResult('r1', new ToolResultData('c1', 'AskEddie', [], 'Out of the Savoy, 1930.'), true, null, 0),
    ]);

    expect($consults)->toBe([['AskEddie', 'Out of the Savoy, 1930.']]);
});

/**
 * The allow-list is positive and never negative. A retrieval tool added next
 * year is dropped because nobody put it on the list, rather than kept because
 * somebody forgot to exclude it -- which is the difference between a boundary
 * and a habit.
 */
it('drops every other tool result even with a consult handler attached', function (): void {
    $passage = 'BLUE LADY 1/2 Blue Curaçao. 1/4 Booth\'s Gin.';

    [, , , $consults] = collectStream([
        new ToolResult('r1', new ToolResultData('c1', 'SearchTheBooks', [], $passage), true, null, 0),
        new ToolResult('r2', new ToolResultData('c2', 'SurveyTheBooks', [], '[{"name":"Gin Fizz"}]'), true, null, 0),
        new ToolResult('r3', new ToolResultData('c3', 'SearchTheHouse', [], '[{"title":"Rambler"}]'), true, null, 0),
        new ToolResult('r4', new ToolResultData('c4', 'BrowseTheMenus', [], '[{"name":"Rambler"}]'), true, null, 0),
        new ToolResult('r5', new ToolResultData('c5', 'ANewToolNobodyThoughtAbout', [], 'secrets'), true, null, 0),
    ]);

    expect($consults)->toBeEmpty();
});

/**
 * A failed result's payload may be an exception message rather than prose. Our
 * own failures never arrive this way -- fail-closed means they come back as
 * *successful* results carrying a sentence -- so anything unsuccessful is the
 * package's own, and not something to print in a bartender's name.
 */
it('does not print a consult that did not run', function (): void {
    [, , , $consults] = collectStream([
        new ToolResult('r1', new ToolResultData('c1', 'AskSasha', [], 'Some internal failure.'), false, 'boom', 0),
        new ToolResult('r2', new ToolResultData('c2', 'AskEddie', [], 'Denied.'), false, null, 0, denied: true),
    ]);

    expect($consults)->toBeEmpty();
});

it('still works when nobody is listening for a consult', function (): void {
    $text = '';

    (new AnswerStream(eddieStream([
        new ToolResult('r1', new ToolResultData('c1', 'AskSasha', [], 'Unheard.'), true, null, 0),
        textDelta('Evening.'),
    ])))->each(function (string $delta) use (&$text): void {
        $text .= $delta;
    });

    expect($text)->toBe('Evening.');
});

/**
 * The token cap ends an answer the way any answer ends, so nothing on the wire
 * says it was cut. The log is the only place it shows -- and the guest's text
 * is exactly what it would have been either way.
 */
it('tells the log, not the guest, when an answer ran into the token cap', function (): void {
    Log::spy();
    config()->set('bar.answers.max_tokens', 1500);

    [$text, , $errors] = collectStream([
        textDelta('A Negroni is equal parts gin, Campari and'),
        new StreamEnd('end-1', 'length', new Usage(completionTokens: 1500), 0),
    ]);

    expect($text)->toBe('A Negroni is equal parts gin, Campari and')
        ->and($errors)->toBe([]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context === [
            'invocation_id' => 'inv-1',
            'max_tokens' => 1500,
            'completion_tokens' => 1500,
        ]);
});

it('says nothing when an answer finished on its own', function (): void {
    Log::spy();

    collectStream([
        textDelta('Stirred, never shaken.'),
        new StreamEnd('end-1', 'stop', new Usage(completionTokens: 40), 0),
    ]);

    Log::shouldNotHaveReceived('warning');
});
