<?php

namespace App\Ai\Streaming;

use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;

/**
 * The one place that decides which of Eddie's stream events a guest may see.
 *
 * The package hands back every event the provider emitted, ToolResult among
 * them -- and a ToolResult carries the whole eight-key passage payload that
 * .ai/rules/retrieval.md keeps out of the model's mouth. Printing it to a
 * terminal, or shipping it down an SSE connection to a browser, is the same
 * leak in two costumes, so the decision lives here rather than at each consumer.
 *
 * Which is also why this class exists rather than a foreach in AskCommand: the
 * JSON API will consume the same stream with a different sink, and this is the
 * part it must not re-decide.
 */
final class AnswerStream
{
    /**
     * In-character names for the tools, keyed by the name the model sees.
     *
     * Laravel\Ai\Tools\ToolNameResolver derives that name from class_basename,
     * so these keys are the tool class names and nothing needs to declare them.
     */
    private const LABELS = [
        'SearchTheBooks' => 'reaching for the books',
        'SurveyTheBooks' => "counting what's on the shelf",
    ];

    public function __construct(private readonly StreamableAgentResponse $response) {}

    /**
     * Walk the stream, handing each consumer the part it is allowed to know about.
     *
     * @param  callable(string $delta): void  $onText
     * @param  (callable(string $label): void)|null  $onTool
     * @param  (callable(string $message): void)|null  $onError
     */
    public function each(callable $onText, ?callable $onTool = null, ?callable $onError = null): void
    {
        $messageId = null;
        $started = false;
        $breakPending = false;

        foreach ($this->response as $event) {
            if ($event instanceof ToolCall) {
                $onTool === null || $onTool($this->label($event->toolCall->name));

                continue;
            }

            if ($event instanceof Error) {
                $onError === null || $onError($event->message);

                continue;
            }

            if (! $event instanceof TextDelta) {
                continue;
            }

            // A multi-step generation starts a new message per step, and each
            // step's text is a self-contained utterance -- usually narration
            // around a tool call. TextDelta::combine() separates them with a
            // blank line; streaming has to do the same or two steps run
            // together mid-sentence. Held until the step actually says
            // something, so a step that emits only whitespace costs nothing.
            $breakPending = $breakPending || ($started && $event->messageId !== $messageId);

            $messageId = $event->messageId;

            // The blocking path trimmed the finished answer. Nothing can be
            // trimmed off the front of a stream after the fact, so leading
            // whitespace is simply never emitted.
            $delta = $started && ! $breakPending ? $event->delta : ltrim($event->delta);

            if ($delta === '') {
                continue;
            }

            if ($breakPending) {
                $onText("\n\n");

                $breakPending = false;
            }

            $started = true;

            $onText($delta);
        }
    }

    private function label(string $tool): string
    {
        return self::LABELS[$tool] ?? $tool;
    }
}
