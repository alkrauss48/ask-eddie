<?php

namespace App\Tools;

use App\Ai\Bar\ConsultRefusal;
use Stringable;

/**
 * Eddie calling across to the house bar.
 *
 * The one path by which a drink name can enter Eddie's mouth without passing
 * his books, which is the rule the whole corpus exists to keep. It survives
 * because his instructions convert what comes back into an *attribution* --
 * "that's Sasha's, over at the house" -- rather than into his own authority. A
 * drink Sasha names gets no book, no year and no page, because it is not on his
 * shelf. EddieAgentTest pins that sentence.
 *
 * Wrapped rather than registered as a raw sub-agent: laravel/ai's AgentTool
 * would call Sasha with no provider or model, so SASHA_TEXT_MODEL would apply
 * when a guest asks her and silently not apply when Eddie does.
 */
class AskSasha extends Consultation
{
    public function description(): Stringable|string
    {
        return 'Call Sasha over — the bartender at the Krauss Haus, a modern bar with its own '
            .'menus. Ask her when a guest wants something from after your time, when the question '
            .'is what a bar pours today, or when your books have nothing and hers might. She '
            .'answers from her own menus, so what she names is hers and not out of your books. '
            .'Once is plenty.';
    }

    protected function bartender(): string
    {
        return 'sasha';
    }

    protected function refusal(ConsultRefusal $refusal): string
    {
        return match ($refusal) {
            ConsultRefusal::Busy => 'Sasha is already at your bar and cannot be in two places at once.',
            ConsultRefusal::Spent => 'Sasha has a room of her own to look after and has gone back to it.',
        };
    }

    protected function unavailable(): string
    {
        return 'The line to the house bar is dead tonight and Sasha cannot be raised.';
    }

    protected function unanswerable(): string
    {
        return 'Sasha waited, but no question came across.';
    }
}
