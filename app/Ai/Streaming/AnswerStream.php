<?php

namespace App\Ai\Streaming;

use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;

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
 *
 * The consult is the single exception, and it is an exception to *what a tool
 * result is*, not to the rule. A consult's result is not a payload -- it is
 * prose one bartender wrote for a human, which the guest is meant to read
 * attributed to whoever said it. So the allow-list below is positive and never
 * negative: a retrieval tool added next year is dropped because nobody put it
 * on the list, rather than kept because somebody forgot to exclude it. That is
 * also why it stays here as a const while the in-character labels live in
 * config('bar.labels') -- the labels are copy, this is the payload boundary,
 * and the two must not become one editable list.
 */
final class AnswerStream
{
    /**
     * The only tools whose results a guest may see.
     *
     * @var list<string>
     */
    private const CONSULTS = ['AskSasha', 'AskEddie'];

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
     * @param  (callable(string $tool, string $answer): void)|null  $onConsult
     *
     * $onConsult is handed the tool's name rather than its label, because the
     * label answers "what is happening" and an attribution answers "who said
     * this" -- a terminal prints one over a quoted block and an SSE sink may
     * want neither. Deciding that here would make this class the place that
     * knows how a consult is presented, which is the consumer's business.
     */
    public function each(
        callable $onText,
        ?callable $onTool = null,
        ?callable $onError = null,
        ?callable $onConsult = null,
    ): void {
        $messageId = null;
        $started = false;
        $breakPending = false;

        foreach ($this->response as $event) {
            if ($event instanceof ToolCall) {
                $onTool === null || $onTool($this->label($event->toolCall->name));

                continue;
            }

            if ($event instanceof ToolResult) {
                // Only a consult, only when it ran. A failed result's payload
                // may be an exception message rather than prose -- our own
                // failures already come back as *successful* results carrying a
                // sentence, which is exactly what fail-closed buys.
                if ($onConsult !== null && $event->successful && in_array($event->toolResult->name, self::CONSULTS, true)) {
                    $onConsult($event->toolResult->name, (string) $event->toolResult->result);
                }

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
