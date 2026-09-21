<?php

use App\Agents\EddieAgent;
use App\Agents\SashaAgent;
use App\Http\Middleware\VerifyBarKey;

/**
 * Who's working, as the website is told it.
 *
 * The point of this route is that a second repository does not have to
 * hard-code "Eddie" and "Sasha" -- so the tests that matter are the one which
 * proves the answer is read from config('bar.bartenders') rather than written
 * out here, and the one which proves the rest of that row stays behind the bar.
 */
beforeEach(function (): void {
    config()->set('bar.api.keys', ['the-house-key']);
});

function roster(): array
{
    return (array) test()
        ->getJson('/api/bartenders', [VerifyBarKey::HEADER => 'the-house-key'])
        ->assertOk()
        ->json('bartenders');
}

it('lists both bartenders with the name and the line the registry gives them', function (): void {
    expect(roster())->toBe([
        [
            'key' => 'eddie',
            'name' => 'Eddie',
            'blurb' => (string) config('bar.bartenders.eddie.blurb'),
        ],
        [
            'key' => 'sasha',
            'name' => 'Sasha',
            'blurb' => (string) config('bar.bartenders.sasha.blurb'),
        ],
    ]);
});

/**
 * Read from the registry, not transcribed from it. A third bartender hired in
 * config/bar.php is on the website without either repository being edited,
 * which is the whole reason this route exists rather than a constant in the
 * site's own code.
 */
it('follows the registry when somebody new is hired', function (): void {
    config()->set('bar.bartenders.margo', [
        'name' => 'Margo',
        'blurb' => 'the one who actually knows where the vermouth is',
    ]);

    expect(collect(roster())->pluck('key')->all())->toBe(['eddie', 'sasha', 'margo'])
        ->and(collect(roster())->firstWhere('key', 'margo'))->toBe([
            'key' => 'margo',
            'name' => 'Margo',
            'blurb' => 'the one who actually knows where the vermouth is',
        ]);
});

/**
 * The allow-list, which is the security half of this route.
 *
 * A registry row also carries the agent class, the retriever class, the corpus
 * and the provider and model each bartender runs on. None of that is a guest's
 * business, and the provider and model in particular are exactly what an
 * attacker sizing up an AI endpoint would like to read off it. Naming three
 * fields rather than unsetting six means a column added next year is absent
 * here by default rather than published by omission.
 */
it('publishes three fields and keeps the rest of the row behind the bar', function (): void {
    config()->set('bar.bartenders.eddie.provider', 'openai');
    config()->set('bar.bartenders.eddie.model', 'gpt-5.6-luna');

    foreach (roster() as $bartender) {
        expect(array_keys($bartender))->toBe(['key', 'name', 'blurb']);
    }

    $body = test()
        ->getJson('/api/bartenders', [VerifyBarKey::HEADER => 'the-house-key'])
        ->getContent();

    expect($body)->not->toContain('EddieAgent')
        ->and($body)->not->toContain('ChunkRetriever')
        ->and($body)->not->toContain('gpt-5.6-luna')
        ->and($body)->not->toContain('books:doctor');
});

/**
 * The roster is configuration, so it costs nothing and can be curled as often
 * as it takes to get a deployment right. Pinned because the obvious way to
 * enrich this response later -- a one-line greeting in each bartender's own
 * voice -- would quietly turn a free health check into a billable one.
 */
it('asks no model, so it costs nothing to call', function (): void {
    EddieAgent::fake([]);
    SashaAgent::fake([]);

    roster();

    EddieAgent::assertNeverPrompted();
    SashaAgent::assertNeverPrompted();
});
