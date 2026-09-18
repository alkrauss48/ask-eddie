<?php

namespace App\Ai\Streaming;

use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;

/**
 * The one place that decides which of a bartender's stream events a guest may see.
 *
 * The package hands back every event the provider emitted, ToolResult among
 * them -- and a ToolResult carries the whole eight-key passage payload that
 * .ai/rules/retrieval.md keeps out of the model's mouth. Printing it to a
 * terminal, or shipping it down an SSE connection to a browser, is the same
 * leak in two costumes, so the decision lives here rather than at each consumer.
 *
 * Which is also why this class exists rather than a foreach in BarAskCommand: the
 * JSON API will consume the same stream with a different sink, and this is the
 * part it must not re-decide.
 */
final class AnswerStream
{
    /**
     * @param  array<string, string>  $labels  in-character tool names, keyed by
     *                                         the name the model sees, which
     *                                         ToolNameResolver derives from
     *                                         class_basename. Defaults to
     *                                         config('bar.labels'); passed in so
     *                                         the JSON API can label the same
     *                                         stream differently without this
     *                                         class knowing either consumer.
     */
    public function __construct(
        private readonly StreamableAgentResponse $response,
        private readonly array $labels = [],
    ) {}

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

    /**
     * An unlabelled tool shows its own class name rather than nothing.
     *
     * Ugly on purpose: a tool added without a label should be visible in the
     * terminal the first time it runs, where a silent fallback would hide the
     * call entirely and make a pause look like a hang.
     */
    private function label(string $tool): string
    {
        return $this->labels[$tool] ?? $tool;
    }
}
