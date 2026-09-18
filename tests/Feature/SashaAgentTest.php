<?php

use App\Agents\SashaAgent;
use App\Tools\BrowseTheMenus;
use App\Tools\SearchTheHouse;

it('keeps both the house pages and the menus behind the bar', function (): void {
    $tools = collect(app(SashaAgent::class)->tools())
        ->map(fn (object $tool): string => $tool::class)
        ->all();

    expect($tools)->toEqualCanonicalizing([SearchTheHouse::class, BrowseTheMenus::class]);
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
