---
paths:
  - routes/api.php
  - app/Http/Controllers/BarAskController.php
  - app/Http/Controllers/BartendersController.php
  - app/Http/Controllers/SearchController.php
  - app/Http/Middleware/VerifyBarKey.php
  - app/Ai/Bar/WebTabKeeper.php
  - app/Ai/Streaming/SseAnswerStream.php
---

# The Web Door

Everything under `/api` is the terminal's `bar:ask` reached over HTTP, wearing
a lock the terminal never needed. Nothing about the books, the menus or the
search changed to build this; the CLI's own reusable pieces (`Bartenders`,
`AnswerStream`, `ConsultDesk`, `TabKeeper`'s idle-window convention) are
threaded through a controller instead of a command. `.ai/rules/bar.md` still
governs all of that machinery; this file is the three things that are
genuinely new — the key, the SSE contract, and the web tab.

## The key list, and why it is a list

`config('bar.api.keys')` is read from `BAR_API_KEYS`, a comma-separated
environment variable, split and trimmed in `config/bar.php` and normalised
again in `VerifyBarKey` (config is writable at runtime, so the guard trusts
nothing about the shape it is handed). It is a **list** rather than a single
value so a key can be rotated without a window where the site is locked out:
add the new one to the front of `BAR_API_KEYS`, move the site over, remove the
old one — two valid keys during the switch, never zero.

An **empty list fails closed**. `VerifyBarKey` refuses every request when
`configuredKeys()` is `[]`, before it even reads what the caller sent — this
is the state the application is in the moment the route file lands, and the
tempting reading ("no keys configured" = "no lock wanted") would put a
billable, model-calling endpoint on the open internet as the consequence of
forgetting to set one environment variable. Every route behind the `bar.key`
middleware group in `routes/api.php` — `/api/ask`, `/api/bartenders`,
`/api/search` — goes dark at once when the list is empty, which is what
`BarWebLockAndPrivacyTest` pins across all three in a single test rather than
trusting that each route's own guard is wired correctly in isolation.

The comparison is `hash_equals`, checked against every key in the list rather
than short-circuiting on the first match (timing-safe both ways), and every
refusal — no key, wrong key, empty key, no keys configured at all — answers
with the identical 401 and sentence, so a caller learns nothing about which
failure they hit.

## The SSE event contract

`SseAnswerStream` frames a `StreamableAgentResponse` as `text/event-stream`
rather than using laravel/ai's own streaming helpers, because those ship the
*raw* event stream — `ToolResult` included, carrying the eight-key passage
payload `.ai/rules/retrieval.md` and `.ai/rules/bar.md` already keep out of a
terminal and a model's own mouth. Shipping that to a browser would be the same
leak with a bigger audience, so the framing is done by hand on top of
`AnswerStream`, the same allow-list the terminal reads through.

Six event types, and this list is the API the Krauss Haus site is written
against — a seventh invented anywhere in this codebase is a frame the site was
never told to expect:

| event | payload | when |
| --- | --- | --- |
| `meta` | `{"conversation_id": string}` | first frame, always — the id to send back on a follow-up |
| `text` | `{"delta": string}` | one piece of the answer, as the provider produces it |
| `tool` | `{"label": string}` | an in-character note while a tool runs (`config('bar.labels')`) |
| `consult` | `{"bartender": string, "answer": string}` | the other bartender's reply to a consult, attributed |
| `error` | `{"message": string}` | the answer failed — a sentence naming the bartender, never the provider's own exception message (that goes to `report()`, for an operator, not a guest) |
| `done` | `{}` | always the last frame, unconditionally, whether the answer succeeded or failed |

`meta` and `done` are `BarAskController`'s own — it is the only thing that
knows the conversation id and knows the connection is still open at the end —
built with `SseAnswerStream::frame()` so the wire format (`event: … \ndata:
…\n\n`, JSON with unescaped slashes/unicode) is written down in exactly one
place. The other four come from `SseAnswerStream::stream()`, bridging
`AnswerStream`'s push-based callbacks onto a pull-based response body with a
`Fiber`.

A 200 is already on the wire by the time a stream can fail — that is what
streaming means — so `error` is the only status code this endpoint has left
once it has started talking. A request rejected before anything is opened
(missing key, bad validation) is answered as ordinary JSON with a real status
code instead; the stream starts only once there is an answer coming.

## Conversation ids are opaque, and reuse never errors

`WebTabKeeper` issues the conversation id — a caller does not get to name
their own tab the way `--tab=` does at the terminal, because a name a caller
can type is a name two callers can type. The id is a fresh UUID handed back in
the opening `meta` frame, and it is the *only* thing that gets a caller back
into their own conversation.

Sending back an id that is not one of ours, one issued for the *other*
bartender, or one that has gone cold (`config('bar.tabs.idle')` minutes, the
same setting that governs the terminal) all produce exactly the same thing: a
brand new conversation, with a fresh id in the `meta` frame, and no error
frame anywhere in the response. This is deliberate, not a missing case —
`WebTabKeeper::reopen()`'s own docblock calls it out. An error would be a
leak: "that id is real but it belongs to someone else" confirms a conversation
exists that the caller has no business knowing about, and "that id is real but
expired" confirms it once existed. Silently falling back to a fresh
conversation gives a caller nothing to learn from an id that isn't theirs.

The bartender check matters as much as the id lookup. `Conversation
participant_type` is written as `tab:{name}:{bartender}`, and
`WebTabKeeper::reopen()` requires the stored row to end in
`:{$bartenderKey}` before it will resume it — so an id issued while asking
Eddie can never be replayed against Sasha. This is not a courtesy: laravel/ai
hydrates a stored assistant row as the *current* agent's own prior turn, with
nothing on the row saying who actually said it, so crossing the tabs would
hand one bartender the other's answers as their own memory — the same
invariant the terminal's per-bartender tabs and the consult's attribution
exist to protect. `BarWebLockAndPrivacyTest` and `BarAskEndpointTest` both pin
it by reading `agent_conversation_messages` back for the fresh conversation
and asserting nothing from the other bartender's turn is in it.

## Rate limiting

`RateLimiter::for('bar-ask', …)` is registered in `AppServiceProvider::boot()`
— there is no `RouteServiceProvider` in this application — and applied to
both `/api/ask` and `/api/search` via `throttle:bar-ask` in `routes/api.php`.
Both routes share the **same bucket**, deliberately: `/api/search` asks no
model, but it is the slowest path in the application (TEI embed, then a
cross-encoder rerank, on an emulated-arm64 host), and a separate limiter for
it would double a caller's effective per-minute allowance rather than bound
it. `/api/bartenders` carries no throttle at all — it reads configuration and
asks nothing, so there is no cost to cap.

The limit is `config('bar.api.rate_limit')` (`BAR_API_RATE_LIMIT`, default 12
— one every five seconds, sustained) per **caller**, not global: the bucket
key is the presented `X-Bar-Key` header, falling back to the request's IP only
for a caller `VerifyBarKey` was always going to refuse anyway. Keying by
caller rather than counting globally is what keeps one noisy key from
exhausting the allowance of every other key on the list — the whole reason
the door supports more than one key.

This is a cap on **how often**, not a budget: it bounds request frequency, not
what any single question costs at the provider. `BarAskController::MAX_QUESTION`
and `SearchController::MAX_QUESTION` (2000 characters) are the separate cap on
**how large** one question can be.
