<?php

use App\Agents\EddieAgent;
use App\Agents\SashaAgent;
use App\Http\Middleware\VerifyBarKey;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Models\Conversation;

/**
 * The two invariants stage 4 exists to prove end to end, against the fully
 * wired system rather than a unit of it: that a conversation id can never
 * carry one bartender's words into the other's context, and that an empty
 * key list closes every door behind bar.key at once, not route by route.
 *
 * BarAskEndpointTest, BarApiKeyTest and SearchEndpointTest already pin these
 * from their own routes' point of view; this file is the cross-route, real-
 * database proof the plan calls for, read back off agent_conversation_messages
 * rather than through the package's own hydration path.
 */
beforeEach(function (): void {
    config()->set('bar.api.keys', ['the-house-key']);

    app()->bind(Reranker::class, fn (): Reranker => new NullReranker);
});

function lockedAsk(array $payload, ?string $key = 'the-house-key'): TestResponse
{
    return test()->postJson('/api/ask', $payload, $key === null ? [] : [VerifyBarKey::HEADER => $key]);
}

/**
 * @return list<array{string, array<string, mixed>}>
 */
function lockedFrames(TestResponse $response): array
{
    $frames = [];

    foreach (explode("\n\n", $response->streamedContent()) as $chunk) {
        if (trim($chunk) === '') {
            continue;
        }

        [$event, $data] = explode("\n", $chunk, 2);

        $frames[] = [substr($event, 7), json_decode(substr($data, 6), true, flags: JSON_THROW_ON_ERROR)];
    }

    return $frames;
}

function lockedConversationId(TestResponse $response): string
{
    $frames = lockedFrames($response);

    expect($frames[0][0])->toBe('meta');

    return (string) $frames[0][1]['conversation_id'];
}

/**
 * @return list<string>
 */
function messagesFor(string $conversationId): array
{
    return DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->pluck('content')
        ->all();
}

/**
 * The privacy invariant that consult, tab and web-tab tests each pin from
 * their own angle: an id issued for one bartender never reaches the other's
 * memory, even when a caller hands it straight back on a different
 * `bartender`. WebTabKeeper falls back to a fresh conversation rather than
 * erroring, so what a caller sees is simply a new id -- and what matters is
 * that Sasha's stored turns, read straight off the table rather than through
 * the agent's own hydration, carry nothing Eddie ever said.
 */
it('never lets a conversation id issued for eddie carry his words into sasha\'s stored messages', function (): void {
    EddieAgent::fake(["That one's out of the Savoy, 1930, page 42."]);
    SashaAgent::fake(['Nothing like that on our menus.']);

    $eddieResponse = lockedAsk(['question' => 'where is the sazerac from?', 'bartender' => 'eddie'])->assertOk();
    $eddieConversationId = lockedConversationId($eddieResponse);

    $sashaResponse = lockedAsk([
        'question' => 'something bright',
        'bartender' => 'sasha',
        'conversation_id' => $eddieConversationId,
    ])->assertOk();
    $sashaConversationId = lockedConversationId($sashaResponse);

    expect($sashaConversationId)->not->toBe($eddieConversationId)
        ->and(Conversation::count())->toBe(2)
        ->and((string) Conversation::find($sashaConversationId)->participant_type)->toEndWith(':sasha');

    $eddieMessages = messagesFor($eddieConversationId);
    $sashaMessages = messagesFor($sashaConversationId);

    expect($eddieMessages)->not->toBe([])
        ->and($sashaMessages)->not->toBe([]);

    foreach ($eddieMessages as $eddieMessage) {
        expect($sashaMessages)->not->toContain($eddieMessage);
    }

    // Concretely: the line only Eddie ever said must not be among Sasha's rows.
    expect(implode("\n", $sashaMessages))->not->toContain('Savoy');
});

/**
 * The fail-closed doctrine VerifyBarKey documents, proved across all three
 * routes behind bar.key at once rather than one at a time. BarApiKeyTest
 * already pins /api/bartenders in isolation; what matters here is that the
 * same empty list closes /api/ask and /api/search too, key or no key, in a
 * single sweep -- so nobody can "fix" one route's lock without the other two
 * going red.
 */
it('refuses every request on every route when no key is configured, key or no key', function (): void {
    config()->set('bar.api.keys', []);
    config()->set('app.debug', true);

    EddieAgent::fake(['Never said.']);

    $askPayload = ['question' => 'what goes in a sazerac?', 'bartender' => 'eddie'];
    $searchPayload = ['question' => 'what goes in a sazerac?', 'bartender' => 'eddie'];

    test()->postJson('/api/ask', $askPayload)->assertUnauthorized();
    test()->postJson('/api/ask', $askPayload, [VerifyBarKey::HEADER => 'the-house-key'])->assertUnauthorized();

    test()->getJson('/api/bartenders')->assertUnauthorized();
    test()->getJson('/api/bartenders', [VerifyBarKey::HEADER => 'the-house-key'])->assertUnauthorized();

    test()->postJson('/api/search', $searchPayload)->assertUnauthorized();
    test()->postJson('/api/search', $searchPayload, [VerifyBarKey::HEADER => 'the-house-key'])->assertUnauthorized();

    EddieAgent::assertNeverPrompted();
    expect(Conversation::count())->toBe(0);
});
