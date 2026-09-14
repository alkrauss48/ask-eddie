<?php

namespace App\Console\Commands;

use App\Agents\EddieAgent;
use App\Services\Retrieval\ChunkRetriever;
use App\Services\Retrieval\RetrievedChunk;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Ask Eddie a question, or just look at what retrieval found.
 *
 * --sources prints both channel ranks beside the fused score, which is the
 * whole reason the ranks survive fusion. A hybrid search where one channel
 * silently returns nothing answers questions perfectly well, slightly worse, in
 * a way no single answer reveals; a column of dashes under "lexical" reveals it
 * immediately. --retrieval-only stops before the language model, so retrieval
 * can be worked on without an API key or a bill.
 */
class AskCommand extends Command
{
    protected $signature = 'eddie:ask
        {question* : What to ask}
        {--sources : Show the retrieved passages, their channel ranks and their fused scores}
        {--retrieval-only : Retrieve and stop, without asking the language model}
        {--limit= : How many passages to retrieve}';

    protected $description = 'Ask Eddie a question, grounded in the book corpus';

    public function handle(ChunkRetriever $retriever, EddieAgent $eddie): int
    {
        $question = implode(' ', (array) $this->argument('question'));

        if ($this->option('sources') || $this->option('retrieval-only')) {
            $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));

            try {
                $results = $retriever->retrieve($question, $limit);
            } catch (Throwable $exception) {
                $this->newLine();
                $this->error('Retrieval failed: '.$exception->getMessage());
                $this->line('<fg=gray>Is tei-embed up? Try `sail artisan books:doctor`.</>');

                return self::FAILURE;
            }

            $this->sources($results);

            if ($this->option('retrieval-only')) {
                return $results->isEmpty() ? self::FAILURE : self::SUCCESS;
            }
        }

        $this->newLine();

        try {
            $response = $eddie->prompt($question);
        } catch (Throwable $exception) {
            $this->error('Eddie could not answer: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach (explode("\n", trim((string) $response->text)) as $line) {
            $this->line('  '.$line);
        }

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
            $this->rank($result, ChunkRetriever::DENSE),
            $this->rank($result, ChunkRetriever::LEXICAL),
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
        foreach ([ChunkRetriever::DENSE, ChunkRetriever::LEXICAL] as $channel) {
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
