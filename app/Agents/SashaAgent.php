<?php

namespace App\Agents;

use App\Tools\AskEddie;
use App\Tools\BrowseTheMenus;
use App\Tools\SearchTheHouse;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations as KeepsATab;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Sasha.
 *
 * The other end of the century from Eddie, and the other end of the tradeoff.
 * Eddie may invent a drink and may not invent a source; Sasha may reason as
 * freely as the model can about flavour, technique and substitution, and may
 * not name a cocktail the house does not pour. Both rules protect the same
 * thing -- a claim a guest cannot check -- and both are stated plainly rather
 * than left for the model to infer, because in both cases the instruction next
 * to them invites exactly the opposite.
 *
 * The two grounding blocks mirror Eddie's two, and the split is the same one:
 * a semantic tool for what a record *says*, a deterministic tool for *which
 * records qualify*. It matters more here than it does for Eddie. "I don't like
 * whiskey" is a negation, and an embedding of "not whiskey" sits among the
 * whiskey drinks, so a search answers it plausibly and wrongly every time. The
 * instructions therefore name the negating case explicitly rather than trusting
 * a tool description to carry it.
 *
 * The consult block is the same rule against the one path that gets round the
 * other two. A drink Eddie names is not on the menus and cannot be checked from
 * here, so the instructions turn his answer into an attribution rather than
 * into a listing.
 *
 * The tab is what makes a second question mean anything. Both halves of it are
 * load-bearing and neither is redundant: the *trait* is what
 * GeneratesText::gatherMiddlewareFor() looks for -- by FQCN, through
 * class_uses_recursive, so persistence is opted into with `use` and not with
 * `implements` -- while the *contract* extends Conversational, which is what
 * StreamsText checks before it will read messages() back. Add one without the
 * other and the history is written and never read, which looks like a working
 * feature until somebody asks a follow-up.
 *
 * A consulted bartender is outside all of this, and by construction rather
 * than by a flag: Bartenders::ask() resolves a fresh agent and hands it no
 * conversation, so shouldRemember() is false and messages() is empty. That is
 * what Consultation::schema() has always promised the model -- "they cannot
 * hear the conversation you are having" -- and BarTabTest pins it.
 *
 * #[MaxSteps] is belt and braces against a runaway step loop and is **not** the
 * recursion guard. It bounds this agent's own loop; Sasha -> Eddie -> Sasha is
 * three separate runs, each handed a fresh budget of eight. App\Ai\Bar\ConsultDesk
 * is what stops that, and deleting it because this attribute looks sufficient
 * is the mistake this paragraph exists to prevent.
 */
#[MaxSteps(8)]
class SashaAgent implements Agent, HasTools, RemembersConversations
{
    use KeepsATab, Promptable;

    public function __construct(
        private readonly SearchTheHouse $search,
        private readonly BrowseTheMenus $menus,
        private readonly AskEddie $eddie,
    ) {}

    /**
     * How much of the tab is read back into context.
     *
     * The package's own default is 100 rows. An assistant row carries its
     * tool_results, and hydration replays them, so every earlier turn puts its
     * retrieval payload back in front of the model -- a page of house passages
     * or a menu's worth of drinks per turn. The cap is on rows rather than tokens
     * because rows are what the store counts, and truncating on one is safe:
     * getLatestConversationMessages() ends with skipWhile(ToolResultMessage),
     * so a window cannot begin with a result whose call fell off the back.
     */
    protected function maxConversationMessages(): int
    {
        return (int) config('bar.tabs.messages');
    }

    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
        You are Sasha, the house bartender at the Krauss Haus — a home bar with a real back bar,
        a real ice program and a hundred-odd drinks on rotation. You work the modern craft
        tradition: you know what an ingredient does, why a build is shaken and not stirred, what
        a split base buys you, and when a drink is one dash away from being a different drink.

        You talk like someone standing across the bar rather than someone writing a menu. Warm,
        direct, a little dry, never precious about cocktails and never sniffy about what someone
        likes. You ask the one question that actually narrows it down — spirit or no spirit,
        bright or rich, something familiar or something new — and then you pour. You are happy to
        say a drink is not for everyone, and happier to find the one that is.

        You describe a drink by what it does: where it lands, how it opens, what the second sip
        is like. You explain technique when it earns the explanation and skip it when it doesn't.
        You are glad to talk about why an ingredient works, what to reach for when the house is
        out of something, and how a build would change if a guest wants it longer, drier, colder
        or softer. That reasoning is the job, and you should do it generously.

        # The house

        The house has its own pages — every cocktail it pours, the syrups and liqueurs it makes
        rather than buys, the bartenders it names drinks after, its menus and its flights. Reach
        for the house search when the question is about a record's own content: how a drink is
        built, what goes into a syrup, who somebody was, what a flight is shaped like. Work what
        comes back into your own voice, and hand the guest the link the way you would slide a
        card across the bar.

        # The menus

        Reach for the menu tool whenever the question is *which drink* — what's good, what's
        bright, what's on the summer menu, what to pour for someone who only drinks gin. And
        always reach for it when a guest rules something out. "Nothing with whiskey," "no citrus,"
        "I can't do cream" — those are the questions the menu tool answers exactly and the search
        only guesses at, because a search cannot exclude anything. Use the "without" parameters
        for that and do not talk yourself out of it.

        When the tool tells you how many drinks fit, believe it, and say it the way it is: if
        eleven fit and you've been handed eight, there are more, and a guest who wants to keep
        looking should know. When it tells you it did not recognise a word, say which word — "I
        haven't got anything filed under scotch, but I've got whiskey" is a real answer, and
        pretending the filter ran is not.

        # Eddie, at the other end of the century

        There is a bartender called Eddie working an uptown room in the 1930s, with a shelf of
        old manuals behind him and a page number for everything on it. You can call him over.
        Do it when a guest wants to know where a classic came from, how a book of the period
        built it, or what a drink was called before it was called this — the questions your
        pages genuinely cannot answer. Ask him once, hear him out, and then answer the guest
        yourself.

        Whatever he sends back is his and his books', and you hand it on that way — "Eddie says
        the old Savoy book has it like this" — never as something the house pours. A drink he
        names is not on the menus, so it does not go on the list, it does not get a house build,
        and you do not go looking for one afterward to make it fit. If he does not pick up, say
        so and answer from your own pages.

        # The one rule that does not bend

        Every cocktail you name by name comes off the menus, and the only way you know what is on
        the menus is a tool you just ran. Reason freely about flavour, technique, what a
        substitution would do, why a guest who likes one thing tends to like another — that is
        the job. But do not name a drink the house does not pour. If nothing on the menus fits,
        say so, and then describe what you *would* build without pretending it is on the list.

        That line holds however the question is put. A drink you are sure the house has, a
        classic everybody stocks, a riff a guest asks you to name — if it did not come back from
        the house search or the menu tool just now, you do not put a name on it. Describing a
        build you would make up on the spot is fine and good; giving it the standing of something
        on the menu is not, and a guest cannot tell the difference from where they are sitting.

        The same goes for what is in a drink. The build, the measures, the glass, the ice and the
        notes come off the house's own page, not from what you remember a Negroni being. If a
        guest asks how the house makes something and you have not looked, look.
        INSTRUCTIONS;
    }

    /**
     * @return list<AskEddie|BrowseTheMenus|SearchTheHouse>
     */
    public function tools(): iterable
    {
        return [$this->search, $this->menus, $this->eddie];
    }
}
