<?php

namespace App\Ai\Bar;

use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * Who works here, and the one place that hands a bartender their provider.
 *
 * This exists for two reasons, and the second is the load-bearing one.
 *
 * It is the only place config('bar.bartenders') is turned into a running
 * agent, so `provider:` and `model:` are threaded in exactly one spot. That is
 * precisely what laravel/ai's own AgentTool cannot do -- it calls prompt() with
 * no provider and no model, so a bartender invoked as somebody else's sub-agent
 * would silently run on config('ai.default') instead of the model their row in
 * the registry names.
 *
 * And it depends on nothing agent-shaped. AskSasha needs SashaAgent, SashaAgent
 * needs AskEddie, AskEddie needs EddieAgent, EddieAgent needs AskSasha: a
 * constructor-injected consult tool makes app(EddieAgent::class) recurse until
 * the container dies, on the first `bar:ask` rather than in some corner. The
 * consult tools hold this registry instead and resolve the other bartender
 * lazily, inside handle(), by which time both agents are already built.
 */
final class Bartenders
{
    /**
     * Ask a bartender a question and wait for the whole answer.
     *
     * Blocking rather than streamed: a consult's result reaches the guest
     * through the parent's stream as one finished utterance, and there is
     * nothing to interleave it with.
     *
     * And deliberately tabless. This is the consult path, and it takes no
     * conversation id on purpose: the agent resolved here is a fresh instance
     * with none set, so laravel/ai neither reads history into it nor writes
     * this exchange down. That is what Consultation::schema() promises the
     * model in as many words -- "they cannot hear the conversation you are
     * having" -- and it is the only reason a drink Sasha names cannot arrive
     * in Eddie's memory as something Eddie said. Do not add a $conversationId
     * parameter here to match stream(); the asymmetry is the feature.
     */
    public function ask(string $key, string $question): string
    {
        $profile = $this->profile($key);

        return $this->agent($key)->prompt(
            $question,
            provider: $profile['provider'],
            model: $profile['model'],
        )->text;
    }

    /**
     * Stream a bartender's answer, on the guest's tab when there is one.
     *
     * A null id is the stateless run this method used to be, and is what
     * BAR_TAB_IDLE=0 produces -- so turning tabs off costs nothing here and
     * changes nothing anywhere else.
     */
    public function stream(string $key, string $question, ?string $conversationId = null): StreamableAgentResponse
    {
        $profile = $this->profile($key);

        $agent = $this->agent($key);

        if ($conversationId !== null && $agent instanceof RemembersConversations) {
            $agent->continue($conversationId);
        }

        return $agent->stream(
            $question,
            provider: $profile['provider'],
            model: $profile['model'],
        );
    }

    public function agent(string $key): Agent
    {
        /** @var Agent */
        return app($this->profile($key)['agent']);
    }

    /**
     * The name a guest is given -- "Sasha", not "sasha".
     */
    public function name(string $key): string
    {
        return (string) $this->profile($key)['name'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        /** @var array<string, mixed>|null */
        return config("bar.bartenders.{$key}");
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys((array) config('bar.bartenders'));
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(string $key): array
    {
        return $this->find($key) ?? throw new InvalidArgumentException("Nobody called \"{$key}\" works here.");
    }
}
