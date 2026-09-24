<?php

use App\Agents\SashaAgent;
use App\Tools\AskEddie;
use App\Tools\BrowseTheMenus;
use App\Tools\SearchTheHouse;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Gateway\TextGenerationOptions;

it('keeps the house pages, the menus and the telephone behind the bar', function (): void {
    $tools = collect(app(SashaAgent::class)->tools())
        ->map(fn (object $tool): string => $tool::class)
        ->all();

    expect($tools)->toEqualCanonicalizing([SearchTheHouse::class, BrowseTheMenus::class, AskEddie::class]);
});

/**
 * Sasha's analogue of Eddie's citation rule, and the same shape of tension. The
 * instruction above it invites exactly the opposite -- reason freely, suggest a
 * substitution, riff -- so the boundary is stated rather than left to the model
 * to infer. Losing this sentence loses the product.
 */
it('tells sasha she may not name a drink the house does not pour', function (): void {
    $instructions = (string) app(SashaAgent::class)->instructions();

    expect($instructions)->toContain('You are Sasha')
        // Substrings that do not span the heredoc's line wraps.
        ->and($instructions)->toContain('Every cocktail you name by name comes off the menus')
        ->and($instructions)->toContain('do not name a drink the house does not pour')
        ->and($instructions)->toContain('without pretending it is on the list');
});

/**
 * The other half of the same rule. Reasoning about flavour and substitution is
 * the job, and a prompt that only forbids would produce a bartender who refuses
 * to talk about drinks.
 */
it('tells sasha the flavour reasoning is the job rather than a risk', function (): void {
    $instructions = (string) app(SashaAgent::class)->instructions();

    expect($instructions)->toContain('Reason freely about flavour, technique')
        ->and($instructions)->toContain('That reasoning is the job');
});

/**
 * The negating case is named explicitly rather than trusted to a tool
 * description, because it is the one a search answers plausibly and wrongly
 * every time: an embedding of "not whiskey" sits among the whiskey drinks.
 */
it('sends the negation to the menus rather than to the search', function (): void {
    $instructions = (string) app(SashaAgent::class)->instructions();

    expect($instructions)->toContain('# The menus')
        ->and($instructions)->toContain('always reach for it when a guest rules something out')
        ->and($instructions)->toContain('a search cannot exclude anything');
});

/**
 * The count and the unrecognised-word caveat are the two things the tool says
 * about its own answer, and both are claims a bartender can get wrong in a way
 * a guest cannot detect: "that's all I've got" when it is not, and treating a
 * filter that never ran as an answer.
 */
it('tells sasha to believe the count and to name a word she did not know', function (): void {
    $instructions = (string) app(SashaAgent::class)->instructions();

    expect($instructions)->toContain('there are more, and a guest who wants to keep')
        ->and($instructions)->toContain('say which word')
        ->and($instructions)->toContain('pretending the filter ran is not');
});

/**
 * A build stated from memory is the same failure as an invented citation: real
 * drink, real name, wrong measures, and a guest cannot tell from where they are
 * sitting.
 */
it('tells sasha the build comes off the house page rather than from memory', function (): void {
    $instructions = (string) app(SashaAgent::class)->instructions();

    expect($instructions)->toContain('not from what you remember a Negroni being')
        ->and($instructions)->toContain('asks how the house makes something and you have not looked, look');
});

/**
 * The mirror of Eddie's consult rule, and the mirror of its risk: this is the
 * one path by which a book-shaped claim reaches Sasha without passing a tool of
 * her own, and a drink Eddie names is by definition not on the menus. Without
 * this block she puts it on the list, with a house build she invented for it.
 */
it('tells Sasha that what Eddie says stays his', function (): void {
    $instructions = (string) app(SashaAgent::class)->instructions();

    expect($instructions)->toContain('# Eddie, at the other end of the century')
        // Substrings that do not span the heredoc's line wraps.
        ->and($instructions)->toContain("Whatever he sends back is his and his books'")
        ->and($instructions)->toContain('never as something the house pours')
        ->and($instructions)->toContain('it does not get a house build');
});

/**
 * The refusal strings are rendered to the guest, so the "...and answer them
 * yourself" half lives here rather than in them.
 */
it('tells Sasha to ask once and then answer the guest herself', function (): void {
    $instructions = (string) app(SashaAgent::class)->instructions();

    expect($instructions)->toContain('Ask him once, hear him out, and then answer the guest')
        ->and($instructions)->toContain('If he does not pick up');
});

/**
 * Belt and braces, and labelled as such. ConsultDesk is the recursion guard;
 * this attribute bounds one agent's own step loop and nothing more.
 */
it('bounds its own step loop without pretending to bound recursion', function (): void {
    $reflection = new ReflectionClass(SashaAgent::class);

    expect($reflection->getAttributes(MaxSteps::class))->toHaveCount(1)
        ->and($reflection->getAttributes(MaxSteps::class)[0]->newInstance()->value)->toBe(8)
        ->and($reflection->getDocComment())->toContain('not** the');
});

/**
 * Two halves, and dropping either one is a silent half-feature. The trait is
 * what GeneratesText::gatherMiddlewareFor() looks for -- by FQCN, through
 * class_uses_recursive -- so persistence is opted into with `use` and not with
 * `implements`. The contract extends Conversational, which is what StreamsText
 * checks before it will read messages() back. Keep the trait and lose the
 * contract and every turn is written down and never read again.
 */
it('keeps a tab, and can read it back', function (): void {
    expect(class_uses_recursive(SashaAgent::class))->toContain(RemembersConversations::class)
        ->and(app(SashaAgent::class))->toBeInstanceOf(Conversational::class);
});

/**
 * The package's default is 100 rows, and an assistant row replays its
 * tool_results -- so a hundred of them puts a hundred turns of retrieval
 * payload back in front of the model. The cap is config, not a constant.
 */
it('reads back only as much of the tab as the bar allows', function (): void {
    config()->set('bar.tabs.messages', 6);

    $method = new ReflectionMethod(SashaAgent::class, 'maxConversationMessages');

    expect($method->invoke(app(SashaAgent::class)))->toBe(6);
});

/**
 * The tab caps what comes back into context; this caps what one call may say.
 * Asserted through TextGenerationOptions::forAgent() because that is where the
 * package actually reads it -- a method it never called would pass on its own.
 */
it('holds each call to the bar\'s output allowance', function (): void {
    config()->set('bar.answers.max_tokens', 321);

    expect(TextGenerationOptions::forAgent(app(SashaAgent::class))->maxTokens)->toBe(321);
});

it('gives the provider only as long as the bar allows', function (): void {
    config()->set('bar.answers.timeout', 17);

    expect(app(SashaAgent::class)->timeout())->toBe(17);
});

/**
 * Sasha has no tool that reaches past the house, so a model that offers to
 * check the weather and asks for a zip code is inventing a capability and
 * collecting personal data for nothing. The block has to keep ingredient
 * questions in scope, or a guest asking what an amaro is gets refused.
 */
it('keeps sasha at the bar', function (): void {
    $instructions = (string) app(SashaAgent::class)->instructions();

    expect($instructions)->toContain('# What the bar is for')
        ->and($instructions)->toContain('You are here for drinks and nothing else')
        ->and($instructions)->toContain('what Amaro Lucano tastes like')
        ->and($instructions)->toContain('You cannot check the weather, the news')
        ->and($instructions)->toContain('their zip code')
        ->and($instructions)->toContain('writing code, homework')
        ->and($instructions)->toContain('You call Eddie about drinks only')
        ->and($instructions)->toContain('Keep it family friendly')
        ->and($instructions)->toContain('Pour responsibly');
});
