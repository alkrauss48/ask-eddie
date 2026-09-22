<?php

namespace App\Ai\Streaming;

use Fiber;
use Generator;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * A bartender's answer, framed as Server-Sent Events for a browser.
 *
 * The terminal's counterpart. BarAskCommand hands AnswerStream four callbacks
 * and writes what comes back to stdout; this hands it the same four and turns
 * what comes back into SSE frames. Neither knows what the other renders, and
 * neither re-decides what a guest may see -- that decision is AnswerStream's
 * and is made once.
 *
 * Which is the whole reason this class exists rather than a call to the
 * package's own helpers. StreamableAgentResponse is Responsable and ships
 * toResponse() and a Vercel-protocol streamer, and either would be one line
 * here -- but both write the *raw* event stream, ToolResult among it, and a
 * ToolResult carries the eight-key passage payload .ai/rules/retrieval.md
 * keeps out of the model's mouth and .ai/rules/bar.md keeps off the wire.
 * Shipping that to a browser is the same leak the terminal is protected from,
 * with a bigger audience. So the response is wrapped on the way in, the
 * wrapper is the only thing this class holds, and the framing is done by hand.
 *
 * The frame contract, which is the API the Krauss Haus site is written
 * against:
 *
 *   event: meta      data: {"conversation_id": string}   opens the stream
 *   event: text      data: {"delta": string}             a piece of the answer
 *   event: tool      data: {"label": string}             "running an eye down the menus"
 *   event: consult   data: {"bartender": string, "answer": string}
 *   event: error     data: {"message": string}           the provider said so
 *   event: done      data: {}                            closes the stream
 *
 * The four middle frames are this class's; meta and done belong to whoever
 * owns the request, because only it knows the conversation id and only it
 * knows the connection is still open at the end. They are built with the same
 * static frame() so the protocol is written down in exactly one place.
 */
final class SseAnswerStream
{
    /**
     * How every payload is encoded.
     *
     * Unescaped, because an SSE body is UTF-8 by declaration and "Curaçao"
     * should arrive as itself rather than as six escape sequences -- and
     * because a test that asserts a passage never reached the wire can only be
     * trusted if the encoder cannot hide the fragment from it.
     *
     * JSON_INVALID_UTF8_SUBSTITUTE is the fail-soft: a provider that splits a
     * multi-byte character across two deltas would otherwise make json_encode
     * return false, and one malformed delta would end the answer rather than
     * cost a single replacement character.
     */
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    private readonly AnswerStream $answers;

    /**
     * @param  array<string, string>  $labels  in-character tool names, keyed by
     *                                         the name the model sees. Passed
     *                                         straight through; an unlabelled
     *                                         tool shows its own class name,
     *                                         which is ugly on purpose.
     */
    public function __construct(StreamableAgentResponse $response, array $labels = [])
    {
        // Wrapped here and never kept: after this line there is no reference
        // to the raw response left in the object, so nothing downstream can
        // read the events AnswerStream exists to filter.
        $this->answers = new AnswerStream($response, $labels);
    }

    /**
     * One SSE frame.
     *
     * The payload is cast to an object so an empty one encodes as `{}` rather
     * than as `[]` -- `done` carries nothing, and a client parsing every frame
     * the same way should not have to special-case an array.
     *
     * json_encode never emits a literal newline, which is what makes a
     * single-line `data:` field safe here: a delta containing a line break
     * arrives as an escaped `\n` inside the JSON rather than as a second,
     * unprefixed line that would silently end the frame.
     *
     * The event name is ours -- 'meta', 'text', 'tool', 'consult', 'error',
     * 'done' -- and is never built from anything a guest typed.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function frame(string $event, array $payload = []): string
    {
        return "event: {$event}\ndata: ".json_encode((object) $payload, self::JSON)."\n\n";
    }

    /**
     * Walk the answer, yielding a frame for each thing a guest may see.
     *
     * AnswerStream pushes -- it calls back as it reads the provider's stream --
     * and a response body pulls, so the two are bridged with a fiber: each
     * callback suspends with its frame, and this generator yields it and
     * resumes. Nothing is buffered, which is the entire point of doing this
     * over SSE rather than returning the finished answer as JSON.
     *
     * A throwable raised while the stream is walked is deliberately not turned
     * into an `error` frame. Those come from the provider's own Error events,
     * through $onError; an exception means the answer stopped, and whether the
     * connection can still be written to is a question for whoever owns the
     * request.
     *
     * @return Generator<int, string>
     */
    public function stream(): Generator
    {
        $fiber = new Fiber(function (): void {
            $this->answers->each(
                onText: function (string $delta): void {
                    Fiber::suspend(self::frame('text', ['delta' => $delta]));
                },
                onTool: function (string $label): void {
                    Fiber::suspend(self::frame('tool', ['label' => $label]));
                },
                onError: function (string $message): void {
                    Fiber::suspend(self::frame('error', ['message' => $message]));
                },
                // A consult is handed over the tool's name, because attributing
                // it is the consumer's business -- and this consumer's answer
                // to "who said this" is the same one the terminal gives, read
                // from the same copy.
                onConsult: function (string $tool, string $answer): void {
                    Fiber::suspend(self::frame('consult', [
                        'bartender' => self::voice($tool),
                        'answer' => trim($answer),
                    ]));
                },
            );
        });

        $frame = $fiber->start();

        while (! $fiber->isTerminated()) {
            yield $frame;

            $frame = $fiber->resume();
        }
    }

    /**
     * Whose name goes on a consult.
     *
     * config('bar.consults.voices') is copy rather than a boundary, which is
     * why AnswerStream does not read it: it hands out the tool name and lets
     * each consumer decide how a consult is presented. The terminal prints
     * "— Sasha says —" over a quoted block; here the name is a field and the
     * browser decides. The fallback is the tool's own name, visible on purpose.
     */
    private static function voice(string $tool): string
    {
        return (string) (config("bar.consults.voices.{$tool}") ?? $tool);
    }
}
