<?php

namespace App\Ai\Bar;

/**
 * Why the desk turned a consult away.
 *
 * An enum rather than a sentence so the desk owns the *decision* and each tool
 * owns the *wording*. Eddie asking for Sasha and Sasha asking for Eddie are
 * turned away for the same two reasons and must say so in two different voices,
 * and a desk that returned prose would have to know how a 1930s bartender
 * talks.
 */
enum ConsultRefusal
{
    /**
     * A consult is already open.
     *
     * Consults are synchronous, so "somebody else is already at the other bar"
     * and "this consult is re-entrant" are the same condition -- which is what
     * makes one boolean an exact one-level depth limit.
     */
    case Busy;

    /**
     * This answer has spent its allowance of consults.
     */
    case Spent;
}
