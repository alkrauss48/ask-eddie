<?php

namespace App\Http\Controllers;

use App\Ai\Bar\Bartenders;
use App\Ai\Bar\ConsultDesk;
use App\Ai\Bar\WebTabKeeper;
use App\Ai\Streaming\SseAnswerStream;
use Generator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Ask a bartender a question over the web.
 *
 * BarAskCommand's answer() with a different sink, and deliberately nothing
 * more. The pieces it stands on were each written to be reused this way:
 * Bartenders threads the provider and model, AnswerStream decides what a guest
 * may see, SseAnswerStream frames that decision for a browser, and
 * WebTabKeeper decides which conversation a request belongs to. This class
 * sequences them and re-decides none of it.
 *
 * ## The shape of the response
 *
 * `meta` first, carrying the conversation id, because the caller needs it
 * whatever happens next -- including when the answer fails half a sentence in,
 * which is exactly the case where "just send it at the end" loses it. `done`
 * last, unconditionally, so a client has one thing to wait for rather than
 * having to treat a closed socket as either success or failure. The frames in
 * between are SseAnswerStream's, and this class does not add to them: a frame
 * type invented here would be one the terminal never sees, which is how the
 * two renderings drift apart.
 *
 * ## The failure is a sentence, not an exception message
 *
 * BarAskCommand prints the provider's own message after "Eddie could not
 * answer:", because the only person reading a terminal is the one running the
 * application. This end of the wire is read by a guest on a website, and a
 * provider exception can carry a URL, a model name, a key fragment or a stack
 * of internals -- so what goes out is the bartender's name and a sentence, and
 * report() puts the real thing in the log where an operator can find it. Same
 * fail-closed doctrine as every tool in this app, one register lower.
 *
 * The frames go out over a 200 that is already on the wire by the time
 * anything can fail, which is the nature of a streamed response: an `error`
 * frame is the only status code this endpoint has once it has started
 * talking.
 */
final class BarAskController extends Controller
{
    /**
     * The longest question the bar will take.
     *
     * Not a limit anyone will meet -- it is four or five paragraphs -- but the
     * field is the one thing a caller controls that is billed by the token,
     * and an unbounded string handed to a provider is a cost with no ceiling
     * on it. The per-minute cap bounds how often; this bounds how large.
     */
    private const MAX_QUESTION = 2000;

    public function __invoke(Request $request, Bartenders $bartenders, WebTabKeeper $tabs): StreamedResponse
    {
        // Rejected before anything is opened, resolved or billed. A bad
        // request is answered as JSON by the handler in bootstrap/app.php,
        // rather than as an SSE stream that exists only to say no -- the
        // stream starts when there is an answer coming.
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:'.self::MAX_QUESTION],
            // The registry is the roster, so a bartender hired in
            // config/bar.php is askable here without this line being touched,
            // and a key that is not on it never reaches Bartenders::stream().
            'bartender' => ['required', 'string', Rule::in($bartenders->keys())],
            // Anything further is WebTabKeeper's judgement: an id that is not
            // one of ours, not for this bartender, or long cold all mean the
            // same thing there -- a new conversation, and no explanation.
            'conversation_id' => ['nullable', 'string'],
        ]);

        $bartender = (string) $validated['bartender'];
        $question = (string) $validated['question'];

        $conversationId = $tabs->resolve($bartender, $validated['conversation_id'] ?? null, $question);

        // One request, one answer, one allowance of consults. The CLI gets
        // away with a fresh process per answer; this does not, and the desk is
        // a singleton -- so a request that did not reset it would inherit
        // whatever the last one spent.
        app(ConsultDesk::class)->reset();

        return response()->stream(function () use ($bartenders, $bartender, $question, $conversationId): Generator {
            // The closure has to be a generator in its own right: ResponseFactory
            // reflects on it, and only the generator branch echoes and flushes
            // each chunk as it is produced. A closure that merely *returned* a
            // generator would be called for its side effects and send nothing.
            yield from $this->frames($bartenders, $bartender, $question, $conversationId);
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * The whole response body, one frame at a time.
     *
     * @return Generator<int, string>
     */
    private function frames(
        Bartenders $bartenders,
        string $bartender,
        string $question,
        string $conversationId,
    ): Generator {
        yield SseAnswerStream::frame('meta', ['conversation_id' => $conversationId]);

        try {
            $stream = $bartenders->stream($bartender, $question, $conversationId);

            yield from (new SseAnswerStream($stream, (array) config('bar.labels')))->stream();
        } catch (Throwable $exception) {
            report($exception);

            yield SseAnswerStream::frame('error', [
                'message' => $bartenders->name($bartender).' could not answer that one, friend.',
            ]);
        }

        yield SseAnswerStream::frame('done');
    }
}
