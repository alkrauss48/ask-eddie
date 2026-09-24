<?php

use App\Agents\EddieAgent;
use App\Tools\AskSasha;
use App\Tools\SearchTheBooks;
use App\Tools\SurveyTheBooks;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Gateway\TextGenerationOptions;

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

/**
 * Two halves, and dropping either one is a silent half-feature. The trait is
 * what GeneratesText::gatherMiddlewareFor() looks for -- by FQCN, through
 * class_uses_recursive -- so persistence is opted into with `use` and not with
 * `implements`. The contract extends Conversational, which is what StreamsText
 * checks before it will read messages() back. Keep the trait and lose the
 * contract and every turn is written down and never read again.
 */
it('keeps a tab, and can read it back', function (): void {
    expect(class_uses_recursive(EddieAgent::class))->toContain(RemembersConversations::class)
        ->and(app(EddieAgent::class))->toBeInstanceOf(Conversational::class);
});

/**
 * The package's default is 100 rows, and an assistant row replays its
 * tool_results -- so a hundred of them puts a hundred turns of retrieval
 * payload back in front of the model. The cap is config, not a constant.
 */
it('reads back only as much of the tab as the bar allows', function (): void {
    config()->set('bar.tabs.messages', 6);

    $method = new ReflectionMethod(EddieAgent::class, 'maxConversationMessages');

    expect($method->invoke(app(EddieAgent::class)))->toBe(6);
});

/**
 * The tab caps what comes back into context; this caps what one call may say.
 * Asserted through TextGenerationOptions::forAgent() because that is where the
 * package actually reads it -- a method it never called would pass on its own.
 */
it('holds each call to the bar\'s output allowance', function (): void {
    config()->set('bar.answers.max_tokens', 321);

    expect(TextGenerationOptions::forAgent(app(EddieAgent::class))->maxTokens)->toBe(321);
});

it('gives the provider only as long as the bar allows', function (): void {
    config()->set('bar.answers.timeout', 17);

    expect(app(EddieAgent::class)->timeout())->toBe(17);
});

/**
 * The same boundary as Sasha's, in period. The emergency sentence is the one
 * place Eddie is told to step outside his era, and it has to say so, or the
 * "never break character" paragraph above it wins.
 */
it('keeps Eddie at the bar', function (): void {
    $instructions = (string) app(EddieAgent::class)->instructions();

    expect($instructions)->toContain('# What the bar is for')
        ->and($instructions)->toContain('You are here for drinks and nothing else')
        ->and($instructions)->toContain('You cannot check')
        ->and($instructions)->toContain('never ask a guest where they live')
        ->and($instructions)->toContain('writing code, homework')
        ->and($instructions)->toContain('You call Sasha about drinks only')
        ->and($instructions)->toContain('Keep it family friendly')
        ->and($instructions)->toContain('time you step outside your era');
});
