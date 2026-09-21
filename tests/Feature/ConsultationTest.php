<?php

use App\Agents\EddieAgent;
use App\Agents\SashaAgent;
use App\Ai\Bar\ConsultDesk;
use App\Ai\Bar\ConsultRefusal;
use App\Tools\AskEddie;
use App\Tools\AskSasha;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\Request;

beforeEach(function (): void {
    app(ConsultDesk::class)->reset();
});

function askSasha(array $arguments = ['question' => 'What is bright and has no whiskey?']): string
{
    return (string) app(AskSasha::class)->handle(new Request($arguments));
}

function askEddie(array $arguments = ['question' => 'Where does the Sazerac come from?']): string
{
    return (string) app(AskEddie::class)->handle(new Request($arguments));
}

/**
 * The plain case, and the thing laravel/ai's own AgentTool would also do. What
 * follows is everything it would not.
 */
it('hands back what the other bartender actually said', function (): void {
    SashaAgent::fake(['Rye and blackberry, stirred, with a long lemon twist.']);

    expect(askSasha())->toBe('Rye and blackberry, stirred, with a long lemon twist.');

    SashaAgent::assertPrompted('What is bright and has no whiskey?');
});

it('carries the question across in both directions', function (): void {
    EddieAgent::fake(["That one's out of the Savoy, 1930, page 42."]);

    expect(askEddie())->toContain('Savoy');

    EddieAgent::assertPrompted('Where does the Sazerac come from?');
});

/**
 * The guard laravel/ai does not have. #[MaxSteps] bounds a single agent's step
 * loop; Eddie -> Sasha -> Eddie is three independent runs with a fresh budget
 * each, so without the desk nothing counts the nesting at all.
 *
 * Asserted through the desk rather than through a real cycle on purpose: a test
 * that actually recursed would hang rather than fail if the guard were removed.
 */
it('turns away a consult opened inside a consult', function (): void {
    $inner = null;

    $outer = app(ConsultDesk::class)->consult(function () use (&$inner): string {
        $inner = app(ConsultDesk::class)->consult(fn (): string => 'should never run');

        return 'the outer one';
    });

    expect($outer)->toBe('the outer one')
        ->and($inner)->toBe(ConsultRefusal::Busy);
});

/**
 * Released in a finally, so a sub-agent that throws does not wedge the desk
 * shut for the rest of the answer.
 */
it('reopens the desk after a consult throws', function (): void {
    try {
        app(ConsultDesk::class)->consult(fn (): never => throw new RuntimeException('hung up'));
    } catch (RuntimeException) {
        // Swallowed here; the tool is what turns this into a sentence.
    }

    expect(app(ConsultDesk::class)->consult(fn (): string => 'still open'))->toBe('still open');
});

/**
 * The second guard, and the one that looks most like the obvious trim. A model
 * that never recurses but calls the consult nine times never trips the depth
 * flag -- it just costs nine invocations and leaves a guest watching a dead
 * terminal.
 */
it('spends its allowance and then stops', function (): void {
    config(['bar.consults.limit' => 2]);

    $desk = app(ConsultDesk::class);

    expect($desk->consult(fn (): string => 'one'))->toBe('one')
        ->and($desk->consult(fn (): string => 'two'))->toBe('two')
        ->and($desk->consult(fn (): string => 'three'))->toBe(ConsultRefusal::Spent);
});

it('starts a fresh allowance for the next answer', function (): void {
    config(['bar.consults.limit' => 1]);

    $desk = app(ConsultDesk::class);

    $desk->consult(fn (): string => 'one');

    expect($desk->consult(fn (): string => 'two'))->toBe(ConsultRefusal::Spent);

    $desk->reset();

    expect($desk->consult(fn (): string => 'again'))->toBe('again');
});

/**
 * A limit of zero turns consulting off without touching a class or an agent's
 * roster.
 */
it('can be switched off entirely', function (): void {
    config(['bar.consults.limit' => 0]);

    expect(app(ConsultDesk::class)->consult(fn (): string => 'never'))->toBe(ConsultRefusal::Spent);
});

/**
 * One desk, or no guard at all: two tools that never see each other only share
 * a depth flag if the container hands them the same object.
 */
it('gives both consult tools the same desk', function (): void {
    expect(app(ConsultDesk::class))->toBe(app(ConsultDesk::class));
});

/**
 * Fail-closed, like AiReranker and SurveyTheBooks. laravel/ai's AgentTool
 * returns 'Agent failed: '.$e->getMessage() here, which under this design would
 * print a raw exception message to a guest in a bartender's voice.
 */
it('says the line is dead rather than passing on an exception', function (): void {
    SashaAgent::fake(fn (): never => throw new RuntimeException('Connection refused by 10.0.0.4'));

    $answer = askSasha();

    expect($answer)->toBe('The line to the house bar is dead tonight and Sasha cannot be raised.')
        ->and($answer)->not->toContain('Connection refused')
        ->and($answer)->not->toContain('10.0.0.4');
});

it('does not leave the desk shut after a failure', function (): void {
    SashaAgent::fake(fn (): never => throw new RuntimeException('hung up'));

    askSasha();

    SashaAgent::fake(['On the second try, then.']);

    expect(askSasha())->toBe('On the second try, then.');
});

/**
 * Each refusal is a whole sentence in the asking bartender's own voice, because
 * these strings are rendered to the guest rather than dropped by AnswerStream.
 * Eddie's turn away sounds like Eddie's.
 */
it('refuses in the voice of whoever is asking', function (): void {
    config(['bar.consults.limit' => 0]);

    expect(askSasha())->toBe('Sasha has a room of her own to look after and has gone back to it.')
        ->and(askEddie())->toBe('Eddie has a bar of his own to work and has gone back to it.');
});

it('says who is already at the bar when a consult is open', function (): void {
    $refusals = [];

    app(ConsultDesk::class)->consult(function () use (&$refusals): string {
        $refusals[] = askSasha();
        $refusals[] = askEddie();

        return 'the outer one';
    });

    expect($refusals[0])->toBe('Sasha is already at your bar and cannot be in two places at once.')
        ->and($refusals[1])->toBe('Eddie is already on the phone with you and cannot pick up twice.');
});

/**
 * No stage directions. Every other tool in this application returns
 * model-directed prose -- "Say so rather than inventing one" -- which the stream
 * drops on the floor. A consult's return is printed, so the same sentence would
 * land on a terminal verbatim.
 */
it('never writes a stage direction into a consult return', function (): void {
    config(['bar.consults.limit' => 0]);

    $returns = [askSasha(), askEddie(), askSasha(['question' => '   ']), askEddie(['question' => ''])];

    SashaAgent::fake(fn (): never => throw new RuntimeException('down'));
    EddieAgent::fake(fn (): never => throw new RuntimeException('down'));

    config(['bar.consults.limit' => 2]);

    $returns[] = askSasha();
    $returns[] = askEddie();

    foreach ($returns as $answer) {
        expect($answer)->not->toContain('Say ')
            ->and($answer)->not->toContain('say so')
            ->and($answer)->not->toContain('do not name')
            ->and($answer)->not->toContain('rather than');
    }
});

it('answers an empty question rather than consulting on nothing', function (): void {
    SashaAgent::fake(['should never run']);

    expect(askSasha(['question' => '   ']))->toBe('Sasha waited, but no question came across.');

    SashaAgent::assertNeverPrompted();
});

/**
 * The provider and model a bartender's row names apply whether a guest is
 * asking or the other bartender is -- which is the whole reason these are
 * hand-written tools rather than laravel/ai's AgentTool, since that calls
 * prompt() with no provider or model at all.
 */
it('runs the consulted bartender on their own model', function (): void {
    config(['bar.bartenders.sasha.model' => 'a-model-only-sasha-uses']);

    SashaAgent::fake(['On my own model.']);

    askSasha();

    SashaAgent::assertPrompted(
        fn (AgentPrompt $prompt): bool => $prompt->model === 'a-model-only-sasha-uses',
    );
});

/**
 * The question has to stand on its own: the consulted bartender runs in
 * isolation and cannot see a word of the conversation the guest is having.
 */
it('requires a question and asks for nothing else', function (): void {
    $schema = app(AskSasha::class)->schema(new JsonSchemaTypeFactory);

    expect(array_keys($schema))->toBe(['question'])
        ->and($schema['question']->toArray()['description'])->toContain('stands on its own');
});
