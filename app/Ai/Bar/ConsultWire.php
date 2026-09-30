<?php

namespace App\Ai\Bar;

use Closure;

/**
 * The line a consult is overheard on.
 *
 * A consult runs inside a tool, and a tool runs inside the parent's stream --
 * laravel/ai executes it synchronously between two of the parent's events -- so
 * while the other bartender is talking, the only code running is the tool.
 * Whoever is rendering the answer cannot reach in, and the tool cannot yield
 * out. This is the slot in between: the consumer taps it before walking the
 * stream, and the tool speaks into it as the other bartender's words arrive.
 *
 * A singleton for the same reason ConsultDesk is one: the tool is resolved by
 * the container inside an agent, and a fresh instance per injection would be a
 * line nobody is listening on.
 *
 * Presentation only. The desk decides whether a consult runs; this decides
 * nothing, and a consult with nobody listening behaves exactly as one that is
 * overheard. What is said into it has already been through the consulted
 * bartender's own AnswerStream, so it is their text and their in-character
 * tool labels -- never a tool payload.
 */
final class ConsultWire
{
    /**
     * @var array{open: Closure(string, string): void, text: Closure(string, string): void, tool: Closure(string, string): void}|null
     */
    private ?array $listener = null;

    /**
     * Start listening, and get back the closure that puts things as they were.
     *
     * Returning a restore rather than offering untap() means a listener
     * installed inside another one hands the line back to it, not to nobody.
     *
     * @param  Closure(string $tool, string $question): void  $onOpen
     * @param  Closure(string $tool, string $delta): void  $onText
     * @param  Closure(string $tool, string $label): void  $onTool
     * @return Closure(): void
     */
    public function tap(Closure $onOpen, Closure $onText, Closure $onTool): Closure
    {
        $previous = $this->listener;

        $this->listener = ['open' => $onOpen, 'text' => $onText, 'tool' => $onTool];

        return function () use ($previous): void {
            $this->listener = $previous;
        };
    }

    /**
     * One bartender has put a question to the other.
     */
    public function opened(string $tool, string $question): void
    {
        $this->listener === null || ($this->listener['open'])($tool, $question);
    }

    /**
     * A piece of the other bartender's reply.
     */
    public function said(string $tool, string $delta): void
    {
        $this->listener === null || ($this->listener['text'])($tool, $delta);
    }

    /**
     * The other bartender has reached for one of their own tools.
     */
    public function reached(string $tool, string $label): void
    {
        $this->listener === null || ($this->listener['tool'])($tool, $label);
    }
}
