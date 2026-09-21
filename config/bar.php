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
