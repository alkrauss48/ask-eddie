<?php

use App\Ai\Streaming\AnswerStream;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Responses\Data\ToolResult as ToolResultData;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
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
 * @return array{string, list<string>, list<string>}
 */
function collectStream(array $events): array
{
    $text = '';
    $tools = [];
    $errors = [];

    (new AnswerStream(eddieStream($events)))->each(
        onText: function (string $delta) use (&$text): void {
            $text .= $delta;
        },
        onTool: function (string $label) use (&$tools): void {
            $tools[] = $label;
        },
        onError: function (string $message) use (&$errors): void {
            $errors[] = $message;
        },
    );

    return [$text, $tools, $errors];
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
