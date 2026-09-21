<?php

namespace App\Ai\Bar;

use Closure;

/**
 * The only thing standing between two bartenders who can call each other.
 *
 * laravel/ai will happily register an Agent as another Agent's tool, and
 * #[MaxSteps] looks like it bounds the result. It does not: it bounds the
 * *parent's* step loop, and Eddie -> Sasha -> Eddie is three independent runs
 * with a fresh budget each. ParentInvocation carries ids for event correlation
 * and keeps no counter. Raw registration therefore has no recursion guard at
 * all, and the failure is not an edge case -- it is the first `bar:ask` that
 * happens to go round twice.
 *
 * Two guards, because they catch two different failures:
 *
 * - The depth flag stops recursion. It is one boolean rather than a counter
 *   because consults are synchronous: while a consult is open, the only code
 *   that can ask for another is the consult itself. Released in a finally, so a
 *   sub-agent that throws does not wedge the desk shut for the rest of the run.
 *
 * - The count stops repetition. A model that never recurses but calls the
 *   consult nine times never trips the depth flag; it just costs nine
 *   invocations and leaves a guest watching a dead terminal. Looks like the
 *   obvious thing to trim, and is not.
 */
final class ConsultDesk
{
    private bool $open = false;

    private int $spent = 0;

    /**
     * Run a consult, or say why it cannot be run.
     *
     * @param  Closure(): string  $consult
     */
    public function consult(Closure $consult): string|ConsultRefusal
    {
        if ($this->open) {
            return ConsultRefusal::Busy;
        }

        if ($this->spent >= $this->limit()) {
            return ConsultRefusal::Spent;
        }

        $this->open = true;
        $this->spent++;

        try {
            return $consult();
        } finally {
            $this->open = false;
        }
    }

    /**
     * Start a fresh answer.
     *
     * The cap is per answer rather than per process, and the desk is a
     * singleton, so somebody has to say where one answer ends. In a CLI run the
     * two are the same thing; under a queue worker or a long-lived server they
     * are not, and a desk that never reset would refuse every consult after the
     * second request it ever served.
     */
    public function reset(): void
    {
        $this->open = false;
        $this->spent = 0;
    }

    private function limit(): int
    {
        return max(0, (int) config('bar.consults.limit'));
    }
}
