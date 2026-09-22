<?php

use App\Agents\EddieAgent;
use App\Agents\SashaAgent;
use App\Ai\Bar\Bartenders;
use App\Ai\Bar\Tab;
use App\Ai\Bar\TabKeeper;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;

beforeEach(function (): void {
    app()->bind(Reranker::class, fn (): Reranker => new NullReranker);
});

function conversations(): Collection
{
    return DB::table('agent_conversations')->orderBy('created_at')->get();
}

function turns(): Collection
{
    return DB::table('agent_conversation_messages')->orderBy('id')->get();
}

/**
 * The whole point. A bar:ask run is a process, so without a tab the second
 * question is asked into a room that has never met the guest.
 */
it('keeps one tab across two runs', function (): void {
    EddieAgent::fake(['Rye, absinthe and Peychauds.', 'Cognac instead of the rye, then.']);

    Artisan::call('bar:ask', ['question' => ['what', 'goes', 'in', 'a', 'sazerac?']]);
    Artisan::call('bar:ask', ['question' => ['make', 'it', 'lighter']]);

    expect(conversations())->toHaveCount(1);

    $turns = turns();

    expect($turns)->toHaveCount(4)
        ->and($turns->pluck('role')->all())->toBe(['user', 'assistant', 'user', 'assistant'])
        ->and($turns->pluck('conversation_id')->unique())->toHaveCount(1)
        ->and($turns[2]->content)->toBe('make it lighter');
});

/**
 * The assertion that fails if only the trait is added and the Conversational
 * contract is dropped: laravel/ai would then write every turn down and never
 * read one back, which looks like a working feature until a guest follows up.
 */
it('reads the tab back into the agent', function (): void {
    EddieAgent::fake(['Rye, absinthe and Peychauds.']);

    Artisan::call('bar:ask', ['question' => ['what', 'goes', 'in', 'a', 'sazerac?']]);

    $conversation = conversations()->first()->id;

    /** @var iterable<Message> $messages */
    $messages = app(EddieAgent::class)->continue($conversation)->messages();

    expect(collect($messages)->map(fn ($message): array => [$message->role->value, $message->content])->all())
        ->toBe([
            ['user', 'what goes in a sazerac?'],
            ['assistant', 'Rye, absinthe and Peychauds.'],
        ]);
});

/**
 * The title is what the guest said, and that is deliberate rather than
 * incidental: laravel/ai names a conversation with an extra call to the
 * provider's cheapestTextModel() when it has to open one itself.
 */
it('names the tab after the first thing the guest said', function (): void {
    EddieAgent::fake(['Rye, absinthe and Peychauds.']);

    Artisan::call('bar:ask', ['question' => ['what', 'goes', 'in', 'a', 'sazerac?']]);

    expect(conversations()->first()->title)->toBe('what goes in a sazerac?');
});

it('opens a fresh tab for --new', function (): void {
    EddieAgent::fake(['Rye, absinthe and Peychauds.', 'Lighter than what, friend?']);

    Artisan::call('bar:ask', ['question' => ['what', 'goes', 'in', 'a', 'sazerac?']]);
    Artisan::call('bar:ask', ['question' => ['make', 'it', 'lighter'], '--new' => true]);

    expect(conversations())->toHaveCount(2)
        ->and(turns()->pluck('conversation_id')->unique())->toHaveCount(2);
});

/**
 * A tab closes when the guest has been gone a while. Without a window the
 * "latest conversation" is forever, and a question asked next month picks up
 * a thread from tonight.
 */
it('closes the tab once the guest has been gone a while', function (): void {
    EddieAgent::fake(['Rye, absinthe and Peychauds.', 'Lighter than what, friend?']);

    Artisan::call('bar:ask', ['question' => ['what', 'goes', 'in', 'a', 'sazerac?']]);

    $this->travel(3)->hours();

    Artisan::call('bar:ask', ['question' => ['make', 'it', 'lighter']]);

    expect(conversations())->toHaveCount(2);
});

/**
 * Zero is the off switch, and off has to mean the stateless run bar:ask was
 * before tabs existed -- not a tab of one.
 */
it('remembers nothing at all when tabs are turned off', function (): void {
    config()->set('bar.tabs.idle', 0);

    EddieAgent::fake(['Rye, absinthe and Peychauds.', 'Lighter than what, friend?']);

    Artisan::call('bar:ask', ['question' => ['what', 'goes', 'in', 'a', 'sazerac?']]);
    Artisan::call('bar:ask', ['question' => ['make', 'it', 'lighter']]);

    expect(conversations())->toHaveCount(0)
        ->and(turns())->toHaveCount(0);
});

/**
 * One tab each, never one between them. laravel/ai replays a stored assistant
 * turn as the *current* agent's own prior words, with nothing on the row to
 * say who said it -- so a shared tab would hand Sasha Eddie's book-cited
 * drinks as her own memory, which is the invariant the consult was built
 * around.
 */
it('keeps eddie and sasha on separate tabs', function (): void {
    EddieAgent::fake(["That one's out of the Savoy, 1930, page 42."]);
    SashaAgent::fake(['Rye and blackberry, stirred.']);

    Artisan::call('bar:ask', ['question' => ['where', 'is', 'the', 'sazerac', 'from?']]);
    Artisan::call('bar:ask', ['question' => ['something', 'bright'], '--bartender' => 'sasha']);

    expect(conversations()->pluck('participant_type')->all())
        ->toBe(['tab:bar:eddie', 'tab:bar:sasha']);

    $hers = conversations()->firstWhere('participant_type', 'tab:bar:sasha')->id;

    /** @var iterable<Message> $messages */
    $messages = app(SashaAgent::class)->continue($hers)->messages();

    expect(collect($messages)->pluck('content')->all())
        ->not->toContain("That one's out of the Savoy, 1930, page 42.");
});

/**
 * "They cannot hear the conversation you are having", pinned. Consultation's
 * schema promises the model exactly that, and it holds by construction rather
 * than by a flag: Bartenders::ask() hands the consulted bartender no tab, so
 * she reads nothing and nothing of hers is written down.
 *
 * The consult itself runs for real -- ToolResult events come from
 * TextGenerationLoop, not the gateway -- so this is the whole path.
 */
it('leaves the consulted bartender off the tab', function (): void {
    SashaAgent::fake(["Rye and blackberry, stirred. It's the Midnight Rambler."]);
    EddieAgent::fake([
        new ToolCallData('c1', 'AskSasha', ['question' => 'What would a modern bar do with rye?']),
        "That's Sasha's, over at the house.",
    ]);

    Artisan::call('bar:ask', ['question' => ['what', 'would', 'a', 'modern', 'bartender', 'do?']]);

    expect(conversations())->toHaveCount(1)
        ->and(conversations()->first()->participant_type)->toBe('tab:bar:eddie')
        ->and(turns()->pluck('agent')->unique()->all())->toBe([EddieAgent::class]);
});

/**
 * The registry is the one place provider and model are threaded, and it is now
 * the one place a tab is too. A null id has to stay the stateless call it was.
 */
it('leaves a bartender stateless when no tab is handed over', function (): void {
    EddieAgent::fake(['Rye, absinthe and Peychauds.']);

    // Consumed, because the middleware persists in a then() callback that
    // fires once the stream has been read to the end.
    iterator_to_array(app(Bartenders::class)->stream('eddie', 'what goes in a sazerac?'));

    expect(conversations())->toHaveCount(0)
        ->and(turns())->toHaveCount(0);
});

it('puts a named tab somewhere of its own', function (): void {
    EddieAgent::fake(['Rye, absinthe and Peychauds.', 'Lighter than what, friend?']);

    Artisan::call('bar:ask', ['question' => ['what', 'goes', 'in', 'a', 'sazerac?']]);
    Artisan::call('bar:ask', ['question' => ['make', 'it', 'lighter'], '--tab' => 'back-room']);

    // Canonically, because created_at is a timestamp(0): two tabs opened in
    // the same second tie, and the order of a tie is the table's physical
    // order, which any other test's rolled-back inserts can move. Which tab
    // was opened first is not what this asserts -- that there are two of them,
    // under their own keys, is.
    expect(conversations()->pluck('participant_type')->all())
        ->toEqualCanonicalizing(['tab:bar:eddie', 'tab:back-room:eddie']);
});

it('picks the tab back up rather than opening a second one', function (): void {
    $tab = new Tab('bar', 'eddie');
    $keeper = app(TabKeeper::class);

    $first = $keeper->resume($tab, 'what goes in a sazerac?');

    expect($keeper->resume($tab, 'make it lighter'))->toBe($first)
        ->and(conversations())->toHaveCount(1);
});
