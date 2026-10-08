<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshHouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The site's way of saying "I've just deployed; come and read me".
 *
 * The house corpus is fetched from the live site, so the site is the one thing
 * that knows when it has changed. It calls this as its server boots, and the
 * answer is an immediate 202: the refresh itself runs after the response, in
 * RefreshHouse, because a full re-embed takes minutes and the site has no
 * reason to wait for it.
 *
 * Accepted means "a refresh is running", not "a new one started": a call that
 * arrives while one is already running is absorbed by the job's unique lock,
 * and the site learns nothing it could act on either way.
 */
final class HouseRefreshController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'checksum' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ]);

        RefreshHouse::dispatch($validated['checksum'] ?? null)->afterResponse();

        return response()->json([
            'message' => 'Refreshing the house catalog.',
        ], Response::HTTP_ACCEPTED);
    }
}
