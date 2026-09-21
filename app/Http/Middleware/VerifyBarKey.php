<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The lock on the door onto the web.
 *
 * There are no users here and nothing to log in to, so the whole of the access
 * control is one shared secret presented in a header. Three things about it are
 * deliberate and each is pinned by a test in BarApiKeyTest.
 *
 * It fails closed on an empty list. An unconfigured key is the state this
 * application is in the moment the route file lands, and the tempting reading
 * of "no keys configured" is "no lock wanted" -- which would put an open,
 * billable endpoint on the internet as the consequence of forgetting to set an
 * environment variable. So no keys means nobody gets in, and the deployment
 * that forgot is a door nobody can open rather than a door standing wide.
 *
 * It compares with hash_equals and does not stop at the first match. A plain
 * === returns as soon as the bytes diverge, which leaks the length of the
 * shared prefix to anyone who can time the response; breaking out of the loop
 * on a hit leaks which key in the list matched. Neither is a practical attack
 * over the open internet against a random secret, but the constant-time
 * comparison costs one function name and the loop costs one boolean, and a
 * reader should not have to decide whether the cheap version was a judgement
 * or an oversight.
 *
 * Every refusal is the same status and the same sentence. A distinct body for
 * "the server has no keys" would tell an unauthenticated caller about the
 * server's configuration, and a distinct one for "that key is not on the list"
 * would confirm the header name is the right one to keep guessing at. One
 * answer to every failure tells them only that they are not getting in.
 */
final class VerifyBarKey
{
    /**
     * The header the key travels in.
     *
     * A header rather than a query parameter: query strings are written to
     * access logs and browser history by default, and this key buys inference
     * that costs money.
     */
    public const HEADER = 'X-Bar-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $keys = $this->configuredKeys();

        if ($keys === []) {
            return $this->refuse();
        }

        $presented = (string) $request->header(self::HEADER, '');

        if ($presented === '' || ! $this->matches($presented, $keys)) {
            return $this->refuse();
        }

        return $next($request);
    }

    /**
     * The accepted keys, normalised.
     *
     * config/bar.php already splits and trims BAR_API_KEYS, but config is
     * writable at runtime -- tests set it directly, and a future deployment
     * could hand the array over some other way -- so the guard normalises
     * rather than trusting whatever it is given. The blank filter is the load
     * bearing half: an empty string left in the list would be a key that
     * matches a caller sending no key at all.
     *
     * @return list<string>
     */
    private function configuredKeys(): array
    {
        return array_values(array_filter(
            array_map(
                fn (mixed $key): string => trim((string) $key),
                array_values((array) config('bar.api.keys')),
            ),
            fn (string $key): bool => $key !== '',
        ));
    }

    /**
     * @param  list<string>  $keys
     */
    private function matches(string $presented, array $keys): bool
    {
        $matched = false;

        foreach ($keys as $key) {
            $matched = hash_equals($key, $presented) || $matched;
        }

        return $matched;
    }

    /**
     * Polite, JSON, and identical whatever went wrong.
     */
    private function refuse(): JsonResponse
    {
        return response()->json([
            'message' => "Sorry, friend — the door's locked, and that's not the key.",
        ], Response::HTTP_UNAUTHORIZED);
    }
}
