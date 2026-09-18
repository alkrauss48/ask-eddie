<?php

namespace App\Console\Commands;

use App\Ai\Streaming\AnswerStream;
use App\Console\IndentedWriter;
use App\Services\Retrieval\HybridRetriever;
use App\Services\Retrieval\RetrievedChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Agent;
use Throwable;

/**
 * Ask one of the bartenders a question, or just look at what retrieval found.
 *
 * --sources prints both channel ranks beside the fused score, which is the
 * whole reason the ranks survive fusion. A hybrid search where one channel
 * silently returns nothing answers questions perfectly well, slightly worse, in
 * a way no single answer reveals; a column of dashes under "lexical" reveals it
 * immediately. --retrieval-only stops before the language model, so retrieval
 * can be worked on without an API key or a bill.
 *
 * --bartender selects a row in config('bar.bartenders'), and that row decides
 * the agent *and* the retriever together. Selecting them separately is the bug
 * this shape exists to prevent: --sources against Sasha's answer showing
 * Eddie's passages would be a table of real citations from the wrong corpus,
 * with nothing on screen to say so.
 */
class BarAskCommand extends Command
{
    protected $signature = 'bar:ask
        {question* : What to ask}
        {--bartender= : Who to ask — eddie or sasha}
        {--sources : Show the retrieved passages, their channel ranks and their fused scores}
        {--retrieval-only : Retrieve and stop, without asking the language model}
        {--limit= : How many passages to retrieve}';

    protected $description = 'Ask a bartender a question, grounded in their own corpus';

    public function handle(): int
    {
        $key = (string) ($this->option('bartender') ?: config('bar.default'));

        /** @var array<string, mixed>|null $bartender */
        $bartender = config("bar.bartenders.{$key}");

        if ($bartender === null) {
            $this->error("Nobody called \"{$key}\" works here.");
            $this->line('  <fg=gray>Behind the bar: '.implode(', ', array_keys((array) config('bar.bartenders'))).'</>');

            return self::FAILURE;
        }

        if ($this->option('sources') || $this->option('retrieval-only')) {
            $status = $this->retrieval($bartender);

            if ($status !== null) {
                return $status;
            }
        }

        return $this->answer($bartender);
    }

    /**
     * Retrieve and print the sources, returning an exit code only when the run
     * stops here.
     *
     * @param  array<string, mixed>  $bartender
     */
    private function retrieval(array $bartender): ?int
    {
        /** @var HybridRetriever $retriever */
        $retriever = app($bartender['retriever']);

        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));

        try {
            $results = $retriever->retrieve(implode(' ', (array) $this->argument('question')), $limit);
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error('Retrieval failed: '.$exception->getMessage());
            $this->line("<fg=gray>Is tei-embed up? Try `sail artisan {$bartender['doctor']}`.</>");

            return self::FAILURE;
        }

        $this->sources($results);

        if ($this->option('retrieval-only')) {
            return $results->isEmpty() ? self::FAILURE : self::SUCCESS;
        }

        return null;
    }

    /**
     * Stream the bartender's answer to the terminal.
     *
     * @param  array<string, mixed>  $bartender
     */
    private function answer(array $bartender): int
    {
        $this->newLine();

        $writer = new IndentedWriter($this->output);

        /** @var Agent $agent */
        $agent = app($bartender['agent']);

        // A blocking prompt() failed before a word had been printed, so the
        // error could simply be written. A stream can fail half a sentence in,
        // which is what close() is for on this path.
        try {
            $stream = $agent->stream(
                implode(' ', (array) $this->argument('question')),
                // Passed at call time rather than declared as a #[Provider]
                // attribute on the agent, so a bartender can be moved to
                // another provider or model with an environment variable.
                provider: $bartender['provider'],
                model: $bartender['model'],
            );

            (new AnswerStream($stream, (array) config('bar.labels')))->each(
                onText: $writer->write(...),
                // Both close the writer first: a tool call can land after the
                // model has already narrated, and a status line tacked onto
                // the end of a bartender's sentence reads as part of it.
                onTool: function (string $label) use ($writer): void {
                    $writer->close();

                    $this->line("  <fg=gray>⋯ {$label}</>");
                },
                onError: function (string $message) use ($writer): void {
                    $writer->close();

                    $this->line("  <fg=yellow>{$message}</>");
                },
            );
        } catch (Throwable $exception) {
            $writer->close();

            $this->error("{$bartender['name']} could not answer: ".$exception->getMessage());

            return self::FAILURE;
        }

        $writer->close();

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, RetrievedChunk>  $results
     */
    private function sources(Collection $results): void
    {
        $this->newLine();

        if ($results->isEmpty()) {
            $this->warn('Nothing retrieved. Both channels came back empty.');

            return;
        }

        $reranked = $results->contains(fn (RetrievedChunk $r): bool => $r->rerankScore !== null);

        $rows = $results->map(fn (RetrievedChunk $result, int $index): array => array_filter([
            (string) ($index + 1),
            $this->rank($result, HybridRetriever::DENSE),
            $this->rank($result, HybridRetriever::LEXICAL),
            number_format($result->score, 5),
            $reranked ? ($result->rerankScore === null ? '—' : number_format($result->rerankScore, 4)) : null,
            $result->chunk->citation,
            $this->excerpt($result->chunk->text),
        ], fn (?string $cell): bool => $cell !== null))->all();

        $this->table(array_filter([
            '#', 'dense', 'lexical', 'fused', $reranked ? 'rerank' : null, 'Citation', 'Text',
        ]), $rows);

        // Named, because "both channels contributed" is the thing being checked
        // and a table of numbers does not say it out loud.
        foreach ([HybridRetriever::DENSE, HybridRetriever::LEXICAL] as $channel) {
            $found = $results->filter(fn (RetrievedChunk $r): bool => $r->rankIn($channel) !== null)->count();

            $this->line(sprintf(
                '  <fg=gray>%s:</> %s',
                $channel,
                $found > 0
                    ? "{$found} of {$results->count()} shown passage(s)"
                    : '<fg=yellow>contributed nothing to this result</>',
            ));
        }
    }

    private function rank(RetrievedChunk $result, string $channel): string
    {
        $rank = $result->rankIn($channel);

        return $rank === null ? '<fg=gray>—</>' : (string) $rank;
    }

    private function excerpt(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;

        return mb_strlen($text) > 70 ? mb_substr($text, 0, 69).'…' : $text;
    }
}
