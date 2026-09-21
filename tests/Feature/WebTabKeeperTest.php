<?php

use App\Agents\EddieAgent;
use App\Ai\Bar\Bartenders;
use App\Ai\Bar\Tab;
use App\Ai\Bar\TabKeeper;
use App\Ai\Bar\WebTabKeeper;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;

beforeEach(function (): void {
    app()->bind(Reranker::class, fn (): Reranker => new NullReranker);

    $this->keeper = app(WebTabKeeper::class);
});

/**
 * The first question opens a conversation, and it is opened *here* rather than
 * left to laravel/ai's middleware -- which, with no users in this application,
 * would answer the first turn and then drop it, and would bill a title against
 * a model nobody chose.
 */
it('opens a conversation up front and hands back its id', function (): void {
    $id = $this->keeper->resolve('eddie', null, 'what goes in a sazerac?');

    $conversation = Conversation::find($id);

    expect($conversation)->not->toBeNull()
        ->and($conversation->title)->toBe('what goes in a sazerac?')
        ->and($conversation->participant_type)->toEndWith(':eddie')
        ->and(Conversation::count())->toBe(1);
});

it('files the conversation under a tab key nothing else can name', function (): void {
    $id = $this->keeper->resolve('sasha', null, 'something bright');

    expect(Conversation::find($id)->participant_type)
        ->toMatch('/^tab:web-[0-9a-f-]{36}:sasha$/');
});

/**
 * Send the id back and the conversation is the same one. This is the whole
 * feature: without it "make it lighter" is asked into a room that has never
 * met the guest.
 */
it('carries on the same conversation when its id comes back', function (): void {
    $first = $this->keeper->resolve('eddie', null, 'what goes in a sazerac?');
    $second = $this->keeper->resolve('eddie', $first, 'make it lighter');

    expect($second)->toBe($first)
        ->and(Conversation::count())->toBe(1);
});

/**
 * Two browsers are two guests. Nothing in a request names a conversation, so
 * there is no value either of them could send that lands them in the other's.
 */
it('gives two visitors two conversations', function (): void {
    $hers = $this->keeper->resolve('eddie', null, 'what goes in a sazerac?');
    $his = $this->keeper->resolve('eddie', null, 'what goes in a sazerac?');

    expect($his)->not->toBe($hers)
        ->and(Conversation::count())->toBe(2);
});

/**
 * One tab per bartender, never one between them -- the invariant the consult
 * was built around. laravel/ai replays a stored assistant turn as the current
 * agent's own prior words with nothing on the row to say who said it, so an id
 * Eddie issued, accepted by Sasha, would hand her his book-cited drinks as her
 * own memory.
 */
it('will not let an id issued for eddie be carried into sasha', function (): void {
    $his = $this->keeper->resolve('eddie', null, 'where is the sazerac from?');

    $hers = $this->keeper->resolve('sasha', $his, 'something bright');

    expect($hers)->not->toBe($his)
        ->and(Conversation::find($hers)->participant_type)->toEndWith(':sasha')
        ->and(Conversation::count())->toBe(2);
});

/**
 * A tab closes once the guest has been gone a while, on the web for the same
 * reason and after the same quiet as at the terminal: without a window, an id
 * kept in a browser tab over a weekend reopens a thread from Friday.
 */
it('will not reopen a conversation that has gone cold', function (): void {
    $first = $this->keeper->resolve('eddie', null, 'what goes in a sazerac?');

    $this->travel((int) config('bar.tabs.idle') + 1)->minutes();

    expect($this->keeper->resolve('eddie', $first, 'make it lighter'))
        ->not->toBe($first)
        ->and(Conversation::count())->toBe(2);
});

it('reopens a conversation that is still inside the idle window', function (): void {
    $first = $this->keeper->resolve('eddie', null, 'what goes in a sazerac?');

    $this->travel((int) config('bar.tabs.idle') - 1)->minutes();

    expect($this->keeper->resolve('eddie', $first, 'make it lighter'))->toBe($first)
        ->and(Conversation::count())->toBe(1);
});

/**
 * Every refusal has to look like every other one. An error naming the reason
 * would say whether an id is real, whose it is and whether it once existed --
 * all of which are facts about somebody else's conversation.
 */
it('says nothing at all about an id it will not accept', function (string $sent): void {
    $id = $this->keeper->resolve('eddie', $sent, 'make it lighter');

    expect($id)->toBeString()
        ->and(Conversation::find($id))->not->toBeNull();
})->with([
    'never existed' => fn (): string => (string) Str::uuid(),
    'not an id at all' => "'; drop table agent_conversations; --",
    'empty' => '',
]);

/**
 * The terminal's tabs and the web's are looked up in completely different ways
 * -- by name there, by id here -- and neither may surface the other's rows. A
 * guest asking over the web must not land on whatever was last typed at the
 * terminal, and `bar:ask` must not pick a visitor's conversation up.
 */
it('keeps the web and the terminal out of each other', function (): void {
    $tabs = app(TabKeeper::class);
    $terminal = $tabs->start(new Tab('bar', 'eddie'), 'what goes in a sazerac?');

    $web = $this->keeper->resolve('eddie', null, 'what goes in a sazerac?');

    expect($web)->not->toBe($terminal)
        ->and($tabs->open(new Tab('bar', 'eddie')))->toBe($terminal);
});

/**
 * Zero is the off switch, and it is the terminal's switch -- one number, not a
 * second one for the web to drift away from. A caller is always owed an id, so
 * off here can only mean that no id is ever accepted back.
 */
it('accepts no id at all when tabs are turned off', function (): void {
    config()->set('bar.tabs.idle', 0);

    $first = $this->keeper->resolve('eddie', null, 'what goes in a sazerac?');

    expect($this->keeper->resolve('eddie', $first, 'make it lighter'))->not->toBe($first);
});

/**
 * The id is only worth anything if Bartenders::stream() will take it, so this
 * runs the real thing twice: nothing between resolve() and a persisted second
 * turn is stubbed but the model itself.
 */
it('produces an id a bartender will actually remember against', function (): void {
    EddieAgent::fake(['Rye, absinthe and Peychauds.', 'Cognac instead of the rye, then.']);

    $bartenders = app(Bartenders::class);

    $first = $this->keeper->resolve('eddie', null, 'what goes in a sazerac?');

    // Consumed, because the middleware persists in a then() callback that
    // fires once the stream has been read to the end.
    iterator_to_array($bartenders->stream('eddie', 'what goes in a sazerac?', $first));

    $second = $this->keeper->resolve('eddie', $first, 'make it lighter');

    iterator_to_array($bartenders->stream('eddie', 'make it lighter', $second));

    $turns = DB::table('agent_conversation_messages')->orderBy('id')->get();

    expect($second)->toBe($first)
        ->and(Conversation::count())->toBe(1)
        ->and($turns->pluck('role')->all())->toBe(['user', 'assistant', 'user', 'assistant'])
        ->and($turns->pluck('conversation_id')->unique())->toHaveCount(1);
});
