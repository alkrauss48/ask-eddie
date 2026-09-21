<?php

use App\Agents\EddieAgent;
use App\Tools\AskSasha;
use App\Tools\SearchTheBooks;
use App\Tools\SurveyTheBooks;
use Laravel\Ai\Attributes\MaxSteps;

it('keeps the search, the tally and the telephone behind the bar', function (): void {
    $tools = collect(app(EddieAgent::class)->tools())
        ->map(fn (object $tool): string => $tool::class)
        ->all();

    expect($tools)->toEqualCanonicalizing([SearchTheBooks::class, SurveyTheBooks::class, AskSasha::class]);
});

/**
 * The tally can support a claim about how often a drink was printed and can
 * never support one about how good anyone thought it was, because no book in
 * this corpus rates a drink. The instructions have to draw that line, or Eddie
 * will put a judgement in a book's mouth as fluently as he does everything else.
 */
it('tells Eddie the tally counts printings rather than opinions', function (): void {
    $instructions = (string) app(EddieAgent::class)->instructions();

    expect($instructions)->toContain('# Your tally')
        ->and($instructions)->toContain('not one of these books rates a drink')
        ->and($instructions)->toContain('as your own opinion');
});

/**
 * Coverage is the difference between a true sentence and a false one: the
 * narrative books print no drink headings, so a tally is a claim about the
 * books it could count.
 */
it('tells Eddie to believe the coverage the tally reports', function (): void {
    $instructions = (string) app(EddieAgent::class)->instructions();

    expect($instructions)->toContain('"most of my books" is fair and "all my books" is not');
});

it('still forbids a citation that did not come back from a tool', function (): void {
    $instructions = (string) app(EddieAgent::class)->instructions();

    expect($instructions)->toContain('from a search or a tally you just ran')
        ->and($instructions)->toContain('An invented citation is worse than no citation');
});

/**
 * The consult is the one path by which a drink name can enter Eddie's mouth
 * without passing his books, and the invariant survives only because the
 * instructions convert Sasha's answer into an *attribution* rather than into
 * his own authority. If this block goes, a drink she named comes back with a
 * book, a year and a page invented to dress it up -- every other rule in here
 * still green, and a guest unable to tell.
 */
it('tells Eddie that what Sasha says stays hers', function (): void {
    $instructions = (string) app(EddieAgent::class)->instructions();

    expect($instructions)->toContain('# Sasha, at the other bar')
        // Substrings that do not span the heredoc's line wraps.
        ->and($instructions)->toContain('What comes back is hers, and you pass it on as hers')
        ->and($instructions)->toContain('never as something out of your books')
        ->and($instructions)->toContain('it gets no book, no year and no page');
});

/**
 * The refusal strings are rendered to the guest, so the "...and answer them
 * yourself" half cannot live in them. It lives here instead, with the "ask her
 * once" that keeps a bartender from spending an answer on the telephone.
 */
it('tells Eddie to ask once and then answer the guest himself', function (): void {
    $instructions = (string) app(EddieAgent::class)->instructions();

    expect($instructions)->toContain('Ask her once, hear her out, and then answer the guest yourself')
        ->and($instructions)->toContain('If she cannot come to');
});

/**
 * Belt and braces, and labelled as such: #[MaxSteps] bounds this agent's own
 * step loop, and Eddie -> Sasha -> Eddie is three runs with a fresh budget
 * each. ConsultDesk is the recursion guard; this is not, and the docblock above
 * the attribute says so because someone will otherwise delete the desk.
 */
it('bounds its own step loop without pretending to bound recursion', function (): void {
    $reflection = new ReflectionClass(EddieAgent::class);

    expect($reflection->getAttributes(MaxSteps::class))->toHaveCount(1)
        ->and($reflection->getAttributes(MaxSteps::class)[0]->newInstance()->value)->toBe(8)
        ->and($reflection->getDocComment())->toContain('not** the');
});
