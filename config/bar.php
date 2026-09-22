<?php

use App\Agents\EddieAgent;
use App\Agents\SashaAgent;
use App\Services\Retrieval\ChunkRetriever;
use App\Services\Retrieval\HouseRetriever;

return [

    /*
    |--------------------------------------------------------------------------
    | Who Is Behind The Bar
    |--------------------------------------------------------------------------
    |
    | Two bartenders, two corpora, one command. `bar:ask --bartender=` selects a
    | row here, and the row decides both the agent and the retriever it is
    | grounded in -- which is what makes `--sources` and `--retrieval-only` work
    | for either of them rather than only for Eddie.
    |
    | Provider and model are configuration rather than attributes on the agent
    | classes, and they are passed at call time. laravel/ai reads #[Provider] and
    | #[Model] attributes off the class, so pinning a bartender to a model that
    | way would mean editing a class to change one; this way SASHA_PROVIDER and
    | SASHA_TEXT_MODEL are environment variables, and the two can run on
    | different providers without either class knowing. Null means "whatever
    | config('ai.default') says", which is the behaviour Eddie had before this
    | file existed.
    |
    */

    'bartenders' => [

        'eddie' => [
            'name' => 'Eddie',
            'agent' => EddieAgent::class,
            'retriever' => ChunkRetriever::class,
            'corpus' => 'books',
            'provider' => env('EDDIE_PROVIDER'),
            'model' => env('EDDIE_TEXT_MODEL'),
            'blurb' => 'a 1930s uptown bartender, grounded in a shelf of public-domain manuals',

            /*
            | The hint printed when retrieval fails. Both corpora embed through
            | the same TEI container, so the cause is the same one in both cases
            | -- but the command that checks it is not.
            */
            'doctor' => 'books:doctor',
        ],

        'sasha' => [
            'name' => 'Sasha',
            'agent' => SashaAgent::class,
            'retriever' => HouseRetriever::class,
            'corpus' => 'house',
            'provider' => env('SASHA_PROVIDER'),
            'model' => env('SASHA_TEXT_MODEL'),
            'blurb' => "the house bartender, grounded in the Krauss Haus's own menus",
            'doctor' => 'house:status',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Default Bartender
    |--------------------------------------------------------------------------
    |
    | Eddie, because he was here first and `eddie:ask "gin fizz"` should keep
    | working as `bar:ask "gin fizz"` with nothing else typed.
    |
    */

    'default' => env('BAR_DEFAULT_BARTENDER', 'eddie'),

    /*
    |--------------------------------------------------------------------------
    | In-Character Tool Labels
    |--------------------------------------------------------------------------
    |
    | What a guest sees while a tool runs. Laravel\Ai\Tools\ToolNameResolver
    | derives the name the model sees from class_basename, so these keys are the
    | tool class names and nothing needs to declare them.
    |
    | These were a private const on AnswerStream. They moved here when the
    | second bartender arrived: the stream is the one place that decides what a
    | guest may see of a tool call, and that decision should not also be the
    | place that knows how each bartender talks about their own tools. An
    | unlisted tool falls back to its class name, which is ugly and visible --
    | deliberately, because a silent fallback to nothing would hide a tool call
    | entirely.
    |
    */

    'labels' => [
        'SearchTheBooks' => 'reaching for the books',
        'SurveyTheBooks' => "counting what's on the shelf",
        'SearchTheHouse' => 'checking the house pages',
        'BrowseTheMenus' => 'running an eye down the menus',
        'AskSasha' => 'calling Sasha over',
        'AskEddie' => 'calling Eddie over',
    ],

    /*
    |--------------------------------------------------------------------------
    | Consulting The Other Bartender
    |--------------------------------------------------------------------------
    |
    | Each bartender carries a tool that calls the other one over, and the guest
    | sees the reply attributed rather than absorbed. Two knobs, guarding two
    | different failures -- see App\Ai\Bar\ConsultDesk, which holds both.
    |
    | The limit is per answer, not per process. Two is a conversation: enough
    | that Eddie can ask Sasha and then, having heard her, ask about something
    | she said; few enough that a model which has decided consulting is the
    | answer to everything runs out rather than running on. Zero turns consults
    | off without touching a class or an agent's roster.
    |
    | "voices" is the name printed over a consult's reply in the terminal. It is
    | copy, so it lives here; which tool results may be shown to a guest at all
    | is a payload boundary, so that stays as an allow-list inside AnswerStream.
    | The two are deliberately not the same list.
    |
    */

    'consults' => [

        'limit' => (int) env('BAR_CONSULT_LIMIT', 2),

        'voices' => [
            'AskSasha' => 'Sasha',
            'AskEddie' => 'Eddie',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | The Guest's Tab
    |--------------------------------------------------------------------------
    |
    | A bar:ask run is a process, so "the same conversation" is not something
    | the runtime knows -- it has to be decided, and the decision here is a
    | bar's. The guest has a tab. It stays open while they keep asking and
    | closes once they have been gone a while, and the next question picks up
    | where the last one left off with nothing typed.
    |
    | One tab per bartender, keyed by name and bartender together, and never
    | one shared between them. laravel/ai replays a stored assistant turn as
    | the *current* agent's own prior words, with nothing on the row to say who
    | said it -- so a shared tab would hand Sasha Eddie's book-cited drinks as
    | her own memory. That is the invariant the consult was built around and
    | the one Consultation::schema() promises the model is impossible.
    |
    | "idle" is minutes of quiet before the tab closes. Zero turns memory off
    | entirely and restores the stateless run bar:ask was before tabs existed,
    | without touching a class -- the same shape as consults.limit above.
    |
    | "messages" caps how many stored rows are read back into context. The
    | package's own default is 100. An assistant row carries its tool_results
    | and hydration replays them, so each earlier turn puts its retrieval
    | payload back in front of the model; a dozen rows is six exchanges, which
    | is a conversation, and a hundred is a bill.
    |
    */

    'tabs' => [

        'default' => env('BAR_TAB', 'bar'),

        'idle' => (int) env('BAR_TAB_IDLE', 120),

        'messages' => (int) env('BAR_TAB_MESSAGES', 12),

    ],

    /*
    |--------------------------------------------------------------------------
    | The Length Of An Answer
    |--------------------------------------------------------------------------
    |
    | The tab caps how much of the past comes back into context; this caps how
    | much of the present a single answer may run to. Without it, a bartender
    | who gets going talks until the provider's own ceiling stops him, and the
    | provider's ceiling is a bill, not a bar's judgement.
    |
    | "max_tokens" is output tokens per model call -- per *step*, not per
    | answer. An answer that searches and then speaks is two or three calls,
    | each with its own allowance, so the most one answer can say is this
    | times #[MaxSteps], plus whatever a consult says on its own budget.
    |
    | "timeout" is seconds per provider HTTP call, and the package's own
    | default is the same sixty -- it is written down here so it is a decision
    | rather than an accident. It is not a wall clock on the whole stream: that
    | is bounded by MaxSteps times this, plus the consults.
    |
    | Both are read by the agents themselves (maxTokens() and timeout()), so a
    | consulted bartender is held to them too.
    |
    */

    'answers' => [

        'max_tokens' => (int) env('BAR_ANSWER_MAX_TOKENS', 1500),

        'timeout' => (int) env('BAR_ANSWER_TIMEOUT', 60),

    ],

    /*
    |--------------------------------------------------------------------------
    | The Door Onto The Web
    |--------------------------------------------------------------------------
    |
    | Everything under /api is behind a shared secret. There are no users in
    | this application and there is nothing to log in to, so the whole of the
    | access control is "does the caller hold a key we issued" -- which makes
    | the shape of this list the whole of the security story, and worth saying
    | out loud.
    |
    | It is a list rather than a single value so a new key can be handed to the
    | website before the old one is retired. With one key, rotating it means a
    | window where the site is locked out; with two, you add, switch, and
    | remove, and nothing is ever down. BAR_API_KEYS is comma-separated:
    |
    |     BAR_API_KEYS=the-new-one,the-old-one
    |
    | An empty list is not "open to everyone" -- VerifyBarKey refuses every
    | request when nothing is configured. That is the point: the failure mode
    | of forgetting to set this is a door nobody can open, never a door
    | standing wide. The filter below drops blank segments so a stray comma or
    | a trailing one cannot leave an empty string in the list, which would
    | otherwise be a key that matches a caller sending no key at all.
    |
    | The key belongs on the Krauss Haus *server*, which relays questions. Put
    | it in anything a browser downloads and it is public, and anyone who reads
    | it can spend the AI budget.
    |
    */

    'api' => [

        'keys' => array_values(array_filter(
            array_map(trim(...), explode(',', (string) env('BAR_API_KEYS', ''))),
            fn (string $key): bool => $key !== '',
        )),

        /*
        | Questions per minute, per caller. Keyed by the presented API key
        | rather than counted globally -- see the 'bar-ask' limiter in
        | AppServiceProvider::boot() -- so one noisy key cannot spend another
        | caller's allowance, and a caller with no key at all (which never
        | gets past VerifyBarKey anyway) is bucketed by IP instead. This is
        | not a budget: it bounds *how often*, not how much any one question
        | costs. Twelve is one every five seconds, sustained -- enough for a
        | real conversation, not enough for a script left running.
        */
        'rate_limit' => (int) env('BAR_API_RATE_LIMIT', 12),

    ],

    /*
    |--------------------------------------------------------------------------
    | Menu Browsing
    |--------------------------------------------------------------------------
    |
    | How many drinks BrowseTheMenus hands back when the model does not say.
    | Eight is a conversation's worth: enough that a guest who rules out two
    | things still has something to choose between, few enough that Sasha
    | answers rather than reads out a list.
    |
    */

    'menus' => [
        'limit' => (int) env('BAR_MENU_LIMIT', 8),
    ],

];
