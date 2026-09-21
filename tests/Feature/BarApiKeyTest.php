<?php

use App\Http\Middleware\VerifyBarKey;
use Illuminate\Testing\TestResponse;

/**
 * The lock, exercised through a real route.
 *
 * /api/bartenders is the door these tests knock on because it is the cheapest
 * one -- it reads configuration and asks no model -- but nothing here is about
 * the roster. What is pinned is that the door refuses, and in particular that
 * it refuses in the case a reader is most likely to "simplify" away later: no
 * keys configured at all.
 */
beforeEach(function (): void {
    config()->set('bar.api.keys', ['the-house-key']);
});

function knock(?string $key = null): TestResponse
{
    return test()->getJson('/api/bartenders', $key === null ? [] : [VerifyBarKey::HEADER => $key]);
}

it('lets a caller holding a configured key through', function (): void {
    knock('the-house-key')->assertOk();
});

it('turns away a caller with no key at all', function (): void {
    knock()->assertUnauthorized();
});

it('turns away a caller with the wrong key', function (): void {
    knock('not-the-house-key')->assertUnauthorized();
});

it('turns away a caller sending an empty key', function (): void {
    knock('')->assertUnauthorized();
});

/**
 * hash_equals() is false on a length mismatch, so a caller cannot walk a key
 * out of the server one character at a time. Pinned because str_starts_with,
 * or a loose comparison, would pass every other test in this file.
 */
it('turns away a caller sending a prefix or an extension of a real key', function (): void {
    knock('the-house-ke')->assertUnauthorized();
    knock('the-house-keys')->assertUnauthorized();
});

it('is case sensitive', function (): void {
    knock('THE-HOUSE-KEY')->assertUnauthorized();
});

/**
 * The reason the setting is a list rather than a value. A new key goes on the
 * front, the site is moved over, the old one comes off -- and at no point is
 * the site locked out.
 */
it('accepts any key on the list, so one can be rotated in before the other retires', function (): void {
    config()->set('bar.api.keys', ['the-new-key', 'the-old-key']);

    knock('the-new-key')->assertOk();
    knock('the-old-key')->assertOk();
    knock('a-retired-key')->assertUnauthorized();
});

/**
 * The one that matters most.
 *
 * No keys configured is the state of every deployment that has not been
 * finished yet, and reading it as "no lock wanted" would put a billable
 * endpoint on the open internet as the consequence of a forgotten environment
 * variable. Failing closed makes the cost of forgetting a door nobody can
 * open.
 */
it('refuses everyone when no key is configured, even a caller presenting one', function (): void {
    config()->set('bar.api.keys', []);

    knock()->assertUnauthorized();
    knock('the-house-key')->assertUnauthorized();
    knock('')->assertUnauthorized();
});

/**
 * A stray or trailing comma in BAR_API_KEYS leaves a blank segment behind. A
 * blank surviving into the list would be a key that matches a caller sending
 * no key at all: an open door produced by a typo.
 */
it('never treats a blank entry in the list as a key', function (): void {
    config()->set('bar.api.keys', ['', '   ']);

    knock()->assertUnauthorized();
    knock('')->assertUnauthorized();
    knock('   ')->assertUnauthorized();
});

it('ignores whitespace around a configured key rather than demanding it back', function (): void {
    config()->set('bar.api.keys', ['  the-house-key  ']);

    knock('the-house-key')->assertOk();
});

/**
 * Every refusal is the same status and the same sentence, whatever went wrong.
 * A different body for "the server has no keys" would report the server's
 * configuration to an unauthenticated caller, and a different one for a bad
 * key would confirm the header name is worth guessing at.
 */
it('answers every refusal identically, telling a caller nothing about why', function (): void {
    $nothingSent = knock()->json();
    $wrongKey = knock('not-the-house-key')->json();

    config()->set('bar.api.keys', []);

    $noneConfigured = knock('the-house-key')->json();

    expect($wrongKey)->toBe($nothingSent)
        ->and($noneConfigured)->toBe($nothingSent);
});

/**
 * A refusal is a sentence, not a stack trace. bootstrap/app.php already renders
 * exceptions under api/* as JSON; this pins that the guard's own answer carries
 * nothing but the sentence -- no exception class, no file, no line.
 */
it('refuses politely in JSON, with nothing of the application in it', function (): void {
    $response = knock('not-the-house-key');

    $response->assertUnauthorized()->assertHeader('content-type', 'application/json');

    expect(array_keys((array) $response->json()))->toBe(['message'])
        ->and((string) $response->json('message'))->not->toBeEmpty();
});

/**
 * The guarded route must never run. A refusal that still reached the
 * controller would be a leak dressed as a 401.
 */
it('does not reach what it is guarding', function (): void {
    knock('not-the-house-key')->assertJsonMissingPath('bartenders');
});
