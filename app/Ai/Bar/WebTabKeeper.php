<?php

namespace App\Ai\Bar;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;

/**
 * Which conversation a web request belongs to, and opening one when it belongs
 * to none.
 *
 * TabKeeper answers a question this cannot ask. At a terminal a tab has a name
 * -- `--tab=back-room` -- and the keeper finds the most recent conversation
 * filed under it, because the person typing is the only person there and is
 * trusted to say which conversation is theirs. Over HTTP nobody is trusted to
 * say that. A name a caller can type is a name two callers can type, and two
 * visitors who both said "bar" would be handed each other's chat. So the server
 * makes the id up, hands it back once, and afterwards looks conversations up by
 * that id and by nothing the request chose. An id nobody can guess is the whole
 * of the privacy guarantee here; there is no other lock on a conversation.
 *
 * That inversion -- resolve by id, not by tab name -- is the reason this is a
 * class rather than a method on TabKeeper. The two share the participant_type
 * convention and the idle window and genuinely nothing else, and the convention
 * already has a home in Tab.
 *
 * ## A refusal is indistinguishable from a miss, deliberately
 *
 * An id that does not exist, an id issued for the other bartender and an id
 * that went cold all produce the same thing: a brand new conversation. None of
 * them produces an error, because an error is an answer -- "that id is real but
 * it is Eddie's" tells a caller something about a conversation that is not
 * theirs, and "that id expired" confirms it once existed. The only signal a
 * caller gets is the id they are handed back, and they were always going to be
 * handed one of those.
 *
 * ## The row is created here, for TabKeeper's two reasons
 *
 * Both still hold, and .ai/rules/bar.md spells them out. laravel/ai's
 * RememberConversation middleware persists a turn only when the agent has a
 * participant or already has a conversation id; this application has no users,
 * so a first turn with neither is answered and then dropped, and memory appears
 * to start working from the second question. And when the middleware opens a
 * conversation itself it titles it with an extra call to the provider's
 * cheapestTextModel() -- a model nobody in config/bar.php chose, billed once per
 * visitor. Handing it an id up front is what prevents both.
 *
 * ## One idle window, not two
 *
 * config('bar.tabs.idle') governs the web exactly as it governs the terminal,
 * so there is one number to change rather than two that drift apart. Zero is
 * the off switch, and here it can only mean "reject every id", since a caller
 * is always owed one back: every question then opens its own conversation and
 * no follow-up ever reaches an earlier one, which is the stateless run the
 * terminal gets from the same setting, with a row left behind.
 */
final class WebTabKeeper
{
    public function __construct(private readonly ConversationStore $store) {}

    /**
     * The conversation this question belongs on -- theirs if the id they sent
     * still stands up, a fresh one otherwise.
     */
    public function resolve(string $bartenderKey, ?string $conversationId, string $question): string
    {
        return $this->reopen($bartenderKey, $conversationId)
            ?? $this->start($bartenderKey, $question);
    }

    /**
     * The conversation behind an id a caller sent, or null if it is not one
     * they may carry on -- for any of the reasons, none of which is reported.
     */
    private function reopen(string $bartenderKey, ?string $conversationId): ?string
    {
        $idle = $this->idle();

        // Str::isUuid keeps anything the store could not have issued away from
        // the database, so a caller sending a sentence where an id goes gets a
        // new tab rather than a driver's opinion of their input.
        if ($idle === 0 || $conversationId === null || ! Str::isUuid($conversationId)) {
            return null;
        }

        $conversation = Conversation::find($conversationId);

        if ($conversation === null) {
            return null;
        }

        // The bartender is baked into the key Tab writes, so this is what stops
        // an id Eddie issued from being carried into Sasha's ear. It is the
        // same invariant as the separate terminal tabs and it matters for the
        // same reason: laravel/ai replays a stored assistant turn as the
        // *current* agent's own prior words, with nothing on the row to say who
        // said it. Moving a conversation across would hand one bartender the
        // other's answers as their own memory.
        if (! str_ends_with((string) $conversation->participant_type, ":{$bartenderKey}")) {
            return null;
        }

        if ($conversation->updated_at === null || $conversation->updated_at->lt(now()->subMinutes($idle))) {
            return null;
        }

        return (string) $conversation->getKey();
    }

    /**
     * Open a conversation nobody else can name, titled with what was asked.
     *
     * The tab name is a fresh uuid per visitor rather than anything derived
     * from the request, because a derived name is a guessable one. It carries a
     * "web-" prefix purely so that `select participant_type, title, updated_at
     * from agent_conversations` still says who is on which tab -- the reason
     * participant_type holds a legible key in the first place.
     */
    private function start(string $bartenderKey, string $question): string
    {
        $tab = new Tab('web-'.Str::uuid(), $bartenderKey);

        return $this->store->storeConversation(
            $tab->key(),
            null,
            Str::limit(trim($question), 80, preserveWords: true),
        );
    }

    /**
     * Minutes of quiet before a conversation goes cold -- the terminal's
     * setting, unchanged, because a guest is a guest either way.
     */
    private function idle(): int
    {
        return max(0, (int) config('bar.tabs.idle'));
    }
}
