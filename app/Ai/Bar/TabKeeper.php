<?php

namespace App\Ai\Bar;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;

/**
 * Whether a guest's tab is still open, and opening one when it is not.
 *
 * A CLI run is a process, so "the same session" is not a thing the runtime can
 * tell us -- it has to be decided. The decision is a bar's: the tab stays open
 * while the guest keeps drinking and closes after they have been gone a while.
 * config('bar.tabs.idle') is how long a while is.
 *
 * The conversation row is created *here* rather than left to laravel/ai's
 * RememberConversation middleware, for two reasons that both matter.
 *
 * Its shouldRemember() persists a turn only when the agent has a participant
 * or already has a conversation id. This application has no users, so with
 * neither of those a first turn would be answered and then dropped on the
 * floor, silently, and memory would appear to work from the second question
 * onwards. Creating the row up front is what makes the first turn count.
 *
 * And the middleware generates a conversation title with an extra call to the
 * provider's cheapestTextModel() -- a model nobody in config/bar.php chose,
 * billed once per tab, which is the same silent-provider failure .ai/rules/bar.md
 * disqualifies AgentTool for. It only does that when there is no conversation
 * id yet, so handing it one means the call never happens. The title is what
 * the guest actually said, which is a better name for a tab anyway.
 */
final class TabKeeper
{
    public function __construct(private readonly ConversationStore $store) {}

    /**
     * The conversation still open on this tab, or null if the guest has been
     * gone too long -- or if tabs are turned off entirely.
     */
    public function open(Tab $tab): ?string
    {
        $idle = $this->idle();

        if ($idle === 0) {
            return null;
        }

        return Conversation::query()
            ->where('participant_type', $tab->key())
            ->where('updated_at', '>=', now()->subMinutes($idle))
            ->latest('updated_at')
            ->value('id');
    }

    /**
     * Open a fresh tab, named after the first thing the guest said.
     */
    public function start(Tab $tab, string $question): ?string
    {
        if ($this->idle() === 0) {
            return null;
        }

        return $this->store->storeConversation(
            $tab->key(),
            null,
            Str::limit(trim($question), 80, preserveWords: true),
        );
    }

    /**
     * Pick up where the guest left off, or start them a new one.
     */
    public function resume(Tab $tab, string $question): ?string
    {
        return $this->open($tab) ?? $this->start($tab, $question);
    }

    /**
     * Minutes of quiet before a tab closes. Zero turns memory off without
     * touching a class, the way BAR_CONSULT_LIMIT=0 turns off consults.
     */
    private function idle(): int
    {
        return max(0, (int) config('bar.tabs.idle'));
    }
}
