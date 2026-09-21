<?php

namespace App\Ai\Bar;

/**
 * A guest's tab, which is one name and one bartender.
 *
 * The pair is the whole idea. Eddie and Sasha keep separate tabs on purpose:
 * laravel/ai replays a stored assistant turn as the *current* agent's own
 * prior words, with nothing on the row to say who actually said it. A shared
 * tab would therefore hand Sasha Eddie's book-cited drinks as her own memory
 * -- the exact invariant the consult was built around, and the one
 * Consultation::schema() promises the model is impossible.
 *
 * key() is written into agent_conversations.participant_type, which is
 * nullable in the package's own store signature and indexed alongside
 * updated_at. A tab is not a user and there are no users here, so the column
 * holds a legible string rather than a morph class: `select participant_type,
 * title, updated_at from agent_conversations` then says whose tab is open
 * without a join or a decoder ring. Nothing resolves Conversation::participant().
 */
final class Tab
{
    public function __construct(
        public readonly string $name,
        public readonly string $bartender,
    ) {}

    /**
     * How this tab is written down -- "tab:bar:eddie".
     */
    public function key(): string
    {
        return "tab:{$this->name}:{$this->bartender}";
    }
}
