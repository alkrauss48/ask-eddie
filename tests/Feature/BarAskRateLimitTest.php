<?php

use App\Agents\EddieAgent;
use App\Http\Middleware\VerifyBarKey;
use App\Services\Retrieval\NullReranker;
use App\Services\Retrieval\Reranker;
use Illuminate\Testing\TestResponse;

/**
 * The cap on how often, not on how much.
 *
 * BarAskEndpointTest and BarApiKeyTest already cover the answer and the lock;
 * what is pinned here is the limiter registered in
 * AppServiceProvider::boot() -- that it is per caller rather than global, so
 * one noisy key cannot exhaust another's allowance, and that it is wired onto
 * the real route rather than only existing as a named limiter nobody attached.
 */
beforeEach(function (): void {
    config()->set('bar.api.keys', ['key-one', 'key-two']);
    config()->set('bar.api.rate_limit', 3);

    app()->bind(Reranker::class, fn (): Reranker => new NullReranker);

    EddieAgent::fake(['Never mind the words, friend.']);
});

/**
 * Posts against the real, throttled route -- the header is whatever bucket
 * the test wants to spend from.
 */
function rateLimitedAsk(string $key): TestResponse
{
    return test()->postJson('/api/ask', [
        'question' => 'what goes in a sazerac?',
        'bartender' => 'eddie',
    ], [VerifyBarKey::HEADER => $key]);
}

it('lets every request inside the cap through', function (): void {
    rateLimitedAsk('key-one')->assertOk();
    rateLimitedAsk('key-one')->assertOk();
    rateLimitedAsk('key-one')->assertOk();
});

it('refuses the request that exceeds the cap within the same minute', function (): void {
    rateLimitedAsk('key-one')->assertOk();
    rateLimitedAsk('key-one')->assertOk();
    rateLimitedAsk('key-one')->assertOk();

    rateLimitedAsk('key-one')->assertStatus(429);
});

/**
 * The reason it is keyed by the caller rather than counted globally: a global
 * counter would let one noisy key lock out every other key holder on the same
 * endpoint, which defeats the point of the door having more than one key.
 */
it('gives each api key its own allowance rather than sharing one counter', function (): void {
    rateLimitedAsk('key-one')->assertOk();
    rateLimitedAsk('key-one')->assertOk();
    rateLimitedAsk('key-one')->assertOk();
    rateLimitedAsk('key-one')->assertStatus(429);

    // key-two has spent nothing yet, and gets its own full quota of three --
    // not zero, and not "whatever key-one had left".
    rateLimitedAsk('key-two')->assertOk();
    rateLimitedAsk('key-two')->assertOk();
    rateLimitedAsk('key-two')->assertOk();
    rateLimitedAsk('key-two')->assertStatus(429);
});
