<?php

namespace App\Http\Controllers;

use App\Ai\Bar\Bartenders;
use Illuminate\Http\JsonResponse;

/**
 * Who is working tonight.
 *
 * The Krauss Haus site needs a roster to build a picker from, and the
 * alternative is hard-coding "Eddie" and "Sasha" into a second repository --
 * where a third bartender, or a rewritten blurb, would silently go unmentioned.
 * config('bar.bartenders') is already the single registry that decides who
 * exists; this hands out the part of it a guest is allowed to see.
 *
 * Which is three fields, listed one at a time rather than filtered out of the
 * row. Those rows also carry the agent class, the retriever class and the
 * provider and model each bartender runs on -- infrastructure that tells an
 * attacker what to aim at and tells a guest nothing. An allow-list means a
 * column added to the registry next year is absent here by default, rather
 * than published because nobody remembered to exclude it. This is the same
 * rule AnswerStream applies to tool payloads, for the same reason.
 *
 * It is also the cheapest possible proof that the door and the lock are wired
 * up: it reads configuration, asks no model and costs nothing, so it can be
 * curled as often as it takes to get a deployment right.
 */
final class BartendersController extends Controller
{
    public function __invoke(Bartenders $bartenders): JsonResponse
    {
        $roster = [];

        foreach ($bartenders->keys() as $key) {
            $profile = $bartenders->find($key);

            if ($profile === null) {
                continue;
            }

            $roster[] = [
                'key' => $key,
                'name' => (string) ($profile['name'] ?? $key),
                'blurb' => (string) ($profile['blurb'] ?? ''),
            ];
        }

        return response()->json(['bartenders' => $roster]);
    }
}
