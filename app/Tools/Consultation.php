<?php

namespace App\Tools;

use App\Ai\Bar\Bartenders;
use App\Ai\Bar\ConsultDesk;
use App\Ai\Bar\ConsultRefusal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * One bartender leaning over to ask the other, in view of the guest.
 *
 * Everything invariant about a consult lives here and handle() is final, so a
 * consult cannot be written that skips the desk. Everything that varies is a
 * voice: Eddie turned away sounds like Eddie, Sasha turned away sounds like
 * Sasha, and the desk that made the decision knows nothing about either.
 *
 * **These returns are the one place in this application where a tool's return
 * value is read by a person.** Every other tool returns model-directed prose --
 * "Say so rather than inventing one" -- which AnswerStream drops on the floor.
 * A consult's return is prose one bartender wrote for a human, so AnswerStream
 * renders it, and a stage direction written in here would be printed to the
 * terminal verbatim. The "...so answer the guest yourself" half of each
 * sentence belongs in the agents' instructions, not in the string.
 *
 * Fail-closed, like AiReranker and SurveyTheBooks: the other bar being
 * unreachable costs a capability, never an exception in the middle of an
 * answer. laravel/ai's own AgentTool returns 'Agent failed: '.$e->getMessage()
 * here, which under this design would print a raw exception message to a guest
 * in a bartender's voice.
 */
abstract class Consultation implements Tool
{
    public function __construct(
        protected readonly ConsultDesk $desk,
        protected readonly Bartenders $bartenders,
    ) {}

    final public function handle(Request $request): Stringable|string
    {
        $question = trim((string) $request->string('question'));

        if ($question === '') {
            return $this->unanswerable();
        }

        try {
            $answer = $this->desk->consult(
                fn (): string => $this->bartenders->ask($this->bartender(), $question),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->unavailable();
        }

        if ($answer instanceof ConsultRefusal) {
            return $this->refusal($answer);
        }

        return trim($answer) === '' ? $this->unavailable() : trim($answer);
    }

    /**
     * Nothing is required beyond the question, and the question has to stand on
     * its own: the other bartender runs in isolation and cannot see a word of
     * the conversation the guest is having.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema
                ->string()
                ->description('What to ask them. Write it so it stands on its own — they cannot hear the conversation you are having, so name the drink, the spirit or the occasion in full.')
                ->required(),
        ];
    }

    /**
     * The key in config('bar.bartenders') of whoever is being called over.
     */
    abstract protected function bartender(): string;

    /**
     * What the guest hears when the desk turns the consult away.
     */
    abstract protected function refusal(ConsultRefusal $refusal): string;

    /**
     * What the guest hears when the other bar cannot be reached at all.
     */
    abstract protected function unavailable(): string;

    /**
     * What the guest hears when no question was actually put.
     */
    abstract protected function unanswerable(): string;
}
