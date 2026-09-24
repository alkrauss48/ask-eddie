---
paths:
  - 'app/Ai/Bar/**'
  - app/Tools/Consultation.php
  - app/Tools/AskSasha.php
  - app/Tools/AskEddie.php
  - app/Ai/Streaming/AnswerStream.php
  - app/Agents/EddieAgent.php
  - app/Agents/SashaAgent.php
  - app/Console/Commands/BarAskCommand.php
  - config/ai.php
  - 'app/Agents/**'
---

# The bar, and the consult

## Do not register an Agent as another Agent's tool
`laravel/ai` supports it, and `vendor/laravel/ai/src/Tools/AgentTool.php` disqualifies itself on three counts:

1. `handle()` calls `$this->agent->prompt($request['task'])` with **no provider and no model**, so a bartender invoked as a sub-agent silently runs on `config('ai.default')` instead of the row `config/bar.php` gives them. `SASHA_TEXT_MODEL` would apply when a guest asks her and not when Eddie does, with nothing on screen to say so.
2. Its failure return is `'Agent failed: '.$throwable->getMessage()` — a raw exception message injected into the model's context, which breaks both the character rule and the fail-closed-with-a-sentence doctrine every other tool in this app follows. Under this design it would also be *printed to the guest*.
3. `#[MaxSteps]` bounds the **parent's step loop**, not recursion depth. Eddie → Sasha → Eddie is three independent runs, each handed a fresh budget of eight. `ParentInvocation` tracks ids for event correlation and keeps no counter. Raw registration therefore has **no recursion guard whatsoever**.

`AskSasha` / `AskEddie` also read far better to a model than anything generic, and distinct names are what let `AnswerStream` attribute the reply and allow-list it for rendering.

## The consult tools must resolve their agent lazily
`AskSasha` → `SashaAgent` → `AskEddie` → `EddieAgent` → `AskSasha`. Constructor-injecting the other agent makes `app(EddieAgent::class)` recurse until the container dies, and it fires on the *first* `bar:ask` rather than in some edge case. The tools hold `Bartenders` — which depends on nothing agent-shaped — and resolve the other bartender inside `handle()`, by which time both agents are already built.

`Bartenders` is also the only place provider and model are threaded, which is exactly what `AgentTool` could not do.

## Two guards, because there are two failures
`ConsultDesk` is a **singleton** — a fresh instance per injection would let each tool believe nothing was open, which is the recursion it exists to stop.

- **Depth** is one boolean, not a counter. Consults are synchronous, so "a consult is already open" and "this consult is re-entrant" are the same condition, which makes one flag an exact one-level limit. Released in a `finally` so a sub-agent that throws does not wedge the desk shut.
- **Count** (`config('bar.consults.limit')`, default 2) guards a *different* failure and looks like the obvious trim. Recursion hangs forever; a non-recursive model that calls the consult nine times never trips the depth flag — it just costs nine invocations and leaves a guest watching a dead terminal. Keep it.

The limit is per answer, so `BarAskCommand` calls `ConsultDesk::reset()` before streaming. A CLI run is a process and a process is an answer, but the desk is a singleton and the JSON API will not be.

`#[MaxSteps(8)]` on each agent is belt and braces against a runaway step loop and is **not** the recursion guard. Both class docblocks say so; leave that paragraph in or someone deletes the desk.

## The desk decides, the tool words it
`ConsultDesk::consult()` returns a `ConsultRefusal` enum (`Busy` / `Spent`) rather than a string, so a desk that knows nothing about 1930s diction does not have to write Eddie's dialogue. `Consultation::handle()` is `final`: the mechanism is invariant, the voice is not, and a consult that skipped the desk would be a consult with no guard at all.

## Consult returns are read by a person — this is the one register shift in the app
Every other tool returns model-directed prose (*"Say so rather than inventing one."*) which `AnswerStream` drops on the floor and nobody ever sees. **A consult's return is rendered to the guest.** Getting this backwards puts stage directions on the terminal. The "…so answer the guest yourself" half of each sentence lives in the agents' instructions instead. `ConsultationTest` greps every return for one.

## The allow-list is positive, never negative, and is not the label map
`AnswerStream` drops `ToolResult` so the eight-key passage payload never reaches a terminal or an SSE sink, and that rule is not weakened. A consult's result is not a payload — it is prose one bartender wrote for a human — so `each()` takes a fourth `$onConsult` callback, fired **only** for a tool name in `AnswerStream::CONSULTS` and only when `$event->successful`. A failed result's payload may be an exception message; our own failures already come back as *successful* results carrying a sentence.

`CONSULTS` stays a private const in `AnswerStream` even though the in-character labels moved to `config('bar.labels')`. That split is the whole rule: **the labels are copy, the allow-list is the payload boundary.** A retrieval tool added next year is dropped because nobody put it on the list, not because somebody remembered to exclude it. `config('bar.consults.voices')` is copy too — it is the name printed over the block, and the consumer looks it up, because `AnswerStream` deciding how a consult is presented would make it the class that knows about terminals.

`IndentedWriter` needs no change: it already takes `$indent` in its constructor, and a second instance with `'  │ '` *is* the quoted block. The indent must be plain text — it is written `OUTPUT_RAW`, so a `<fg=gray>` tag would print literally — while the attribution line goes through `line()`, which does interpret styles.

## Both instruction blocks are load-bearing and pinned
The consult is the one path by which a drink name can enter Sasha's mouth without passing her tools, and a book-shaped claim can enter Eddie's without passing his. Both invariants survive **only** because the instructions convert the other's answer into an *attribution* rather than into their own authority — "that's Sasha's, over at the house", "Eddie says the old Savoy book has it like this". A drink Sasha names gets no book, no year and no page; a drink Eddie names does not go on the menus and does not get a house build. `EddieAgentTest` and `SashaAgentTest` pin both with `toContain`; substrings must not span the heredoc's line wraps.

## The end-to-end test is real, and is the most valuable one here
`ToolResult` stream events are emitted by `TextGenerationLoop`, not by the gateway. So `EddieAgent::fake([new ToolCall('c1', 'AskSasha', [...]), 'final text'])` with `SashaAgent::fake([...])` behind it causes the **real** tool to execute through the **real** desk and a **real** `ToolResult` to flow through `AnswerStream` into the terminal rendering. Nothing between the two bartenders is stubbed. Keep it in `BarAskCommandTest`.

Do not write a test that actually recurses to prove the depth guard: with the guard removed it would hang rather than fail. `ConsultationTest` asserts through the desk instead.

---

# The tab

A `bar:ask` run is a process, so "the same conversation" is a decision, not something the runtime knows. `App\Ai\Bar\TabKeeper` makes it: one conversation per `(tab name, bartender)`, resumed while `config('bar.tabs.idle')` minutes have not elapsed since the last turn. `BAR_TAB_IDLE=0` turns memory off and restores the stateless run exactly, the same shape as `BAR_CONSULT_LIMIT=0`.

## The trait and the contract are both load-bearing
An agent needs `use Laravel\Ai\Concerns\RemembersConversations` **and** `implements Laravel\Ai\Contracts\RemembersConversations`. `GeneratesText::gatherMiddlewareFor()` looks for the **trait**, by FQCN through `class_uses_recursive`, to decide whether to persist. `StreamsText`/`GeneratesText` check `$agent instanceof Conversational` to decide whether to read `messages()` back. Keep the trait and drop the contract and every turn is written down and never read again — a feature that looks like it works until a guest follows up. `EddieAgentTest`/`SashaAgentTest` pin both halves. In `App\Agents\*` the concern is imported as `KeepsATab` only because the two share a short name; `class_uses` still reports the real FQCN.

## One tab per bartender, never one between them
`getLatestConversationMessages()` filters on `conversation_id` alone, and hydration rebuilds a stored assistant row as a plain `AssistantMessage` with nothing on it saying who said it. A shared tab therefore hands Sasha Eddie's book-cited drinks as *her own prior words* — worse than the consult, because the consult at least arrives attributed. This is the invariant `.ai/rules/bar.md` protects; do not merge the tabs.

## The consult is tabless by construction, not by a flag
`Bartenders::ask()` takes no conversation id and resolves a fresh agent, so `shouldRemember()` is false and `messages()` is empty: a consulted bartender reads nothing and writes nothing. That is what `Consultation::schema()` promises the model in as many words — *"they cannot hear the conversation you are having"*. Do not add a `$conversationId` parameter to `ask()` to match `stream()`; the asymmetry is the feature, and `BarTabTest` pins it.

## TabKeeper creates the conversation row itself, for two reasons
`RememberConversation::shouldRemember()` persists only when the agent has a participant **or** an existing conversation id. This app has no users, so leaving creation to the middleware would silently drop the first turn of every tab. And the middleware titles a conversation it opens with an extra call to the provider's `cheapestTextModel()` — a model nobody in `config/bar.php` chose, billed per tab, and in tests it eats a queued `Agent::fake()` response because faking swaps the gateway and leaves the provider real. Handing it an id means that call never fires. `config('ai.conversations.generate_title')` is `false` as well, as belt and braces.

## participant_type holds a legible tab key, not a morph class
`tab:bar:eddie`, with `participant_id` null. Both columns are nullable in the store's own signature, there are no users, and the `(participant_type, participant_id, updated_at)` index is exactly the tab lookup. Nothing resolves `Conversation::participant()`.

## The row cap exists because tool results replay
`maxConversationMessages()` is overridden on both agents to `config('bar.tabs.messages')` (12) against the package's default of 100. An assistant row carries its `tool_results` and hydration replays them, so every earlier turn puts its retrieval payload back in front of the model. Truncation is safe: `getLatestConversationMessages()` ends with `skipWhile(ToolResultMessage)`, so a window cannot open on a result whose call fell off the back.

## ConsultDesk::reset() stays per invocation
The consult limit is per *answer*. One `bar:ask` is still one answer, even when the tab spans ten of them.

## BarAskCommand::asked(), not question()
`Illuminate\Console\Command::question()` already exists and is public; a private override is a fatal error.

## Answer length and provider timeout are config-backed methods, not attributes
Both agents define public maxTokens() and timeout() reading config('bar.answers.max_tokens') / ('bar.answers.timeout') (BAR_ANSWER_MAX_TOKENS=1500, BAR_ANSWER_TIMEOUT=60). laravel/ai checks the method before the #[MaxTokens]/#[Timeout] attribute, so a method is how the value stays tunable via env. maxTokens() must be public: TextGenerationOptions::forAgent() calls it from outside the class. Both are per model call (per step), not per answer or per stream. The four caps are separate: rate limit (how often), MAX_QUESTION (how large the question), tabs.messages (how much history comes back), answers.* (how long each call can talk).

## The "What the bar is for" block is the scope guardrail
Both agents end their instructions with a "# What the bar is for" block: drinks only (ingredient questions like "what is Amaro Lucano" stay in scope), no pretending to reach the outside world, never asking for location/zip/age/personal data, off-topic requests (code, homework, advice) get one line and an offer of a drink, the consult is for drinks only, family friendly, pour responsibly, and emergencies override character. Neither agent has a tool that reaches outside the house; do not add one without revisiting this block. EddieAgentTest/SashaAgentTest pin it with toContain.
