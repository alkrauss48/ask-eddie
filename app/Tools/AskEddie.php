<?php

namespace App\Tools;

use App\Ai\Bar\ConsultRefusal;
use Stringable;

/**
 * Sasha calling across to the old bar.
 *
 * The mirror of AskSasha, and the mirror of its risk: this is the one path by
 * which a book-shaped claim can reach Sasha without passing a search of her
 * own, and she has no way to check a page she cannot see. Her instructions
 * therefore convert what comes back into an attribution -- "Eddie says the
 * Savoy has it like this" -- and a drink he names stays off the menus, because
 * the house does not pour it. SashaAgentTest pins that sentence.
 */
class AskEddie extends Consultation
{
    public function description(): Stringable|string
    {
        return 'Call Eddie over — a 1930s bartender with a shelf of old manuals and a page '
            .'number for everything in them. Ask him where a classic comes from, how a book of '
            .'the period built it, or what a drink was called before it was called this. What he '
            .'gives back is out of his books, not off the house menus, so it is his to stand '
            .'behind and not a drink the house pours. Once is plenty.';
    }

    protected function bartender(): string
    {
        return 'eddie';
    }

    protected function refusal(ConsultRefusal $refusal): string
    {
        return match ($refusal) {
            ConsultRefusal::Busy => 'Eddie is already on the phone with you and cannot pick up twice.',
            ConsultRefusal::Spent => 'Eddie has a bar of his own to work and has gone back to it.',
        };
    }

    protected function unavailable(): string
    {
        return 'Nobody is picking up at the old bar just now.';
    }

    protected function unanswerable(): string
    {
        return 'Eddie picked up, but no question came across.';
    }
}
