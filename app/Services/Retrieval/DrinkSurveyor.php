<?php

namespace App\Services\Retrieval;

use App\Models\Book;
use App\Models\Drink;
use App\Models\DrinkMention;
use App\Services\Books\DrinkNameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Counts drinks across the corpus, which is the question retrieval cannot answer.
 *
 * ChunkRetriever finds the eight passages most like a question. That is the
 * wrong shape for "which drink turns up in the most books": eight passages
 * cannot support a claim about twenty-five thousand, and a model handed them
 * will answer from memory instead, which is exactly what the corpus exists to
 * prevent. So this is a plain aggregate over stored counts -- no vector, no
 * fusion, no reranking -- and every row it returns carries citations rendered
 * from a real printed occurrence.
 */
class DrinkSurveyor
{
    public function __construct(private readonly DrinkNameNormalizer $normalizer) {}

    /**
     * @return Collection<int, DrinkSummary>
     */
    public function survey(DrinkQuery $query): Collection
    {
        $drinks = $this->query($query)->get();

        if ($drinks->isEmpty()) {
            return new Collection;
        }

        $citations = $this->citationsFor($drinks->modelKeys());

        return $drinks->map(fn (Drink $drink): DrinkSummary => DrinkSummary::fromDrink(
            $drink,
            $citations->get($drink->id, []),
        ))->values();
    }

    public function coverage(): DrinkCoverage
    {
        $perBook = DrinkMention::query()
            ->selectRaw('book_id, count(*) as total')
            ->groupBy('book_id')
            ->pluck('total')
            ->map(fn ($total): int => (int) $total)
            ->all();

        return new DrinkCoverage(
            booksCounted: count($perBook),
            booksTotal: Book::query()->count(),
            drinkCount: Drink::query()->count(),
            booksCarrying: DrinkCoverage::carrying($perBook),
        );
    }

    /**
     * @return Builder<Drink>
     */
    private function query(DrinkQuery $query)
    {
        $limit = max(1, min($query->limit, (int) config('books.drinks.survey.max_limit')));

        return Drink::query()
            ->when($query->name !== null && $query->name !== '', function ($builder) use ($query) {
                // Matched through the same fold the corpus was stored under, so
                // a guest writing "blue lady" finds "BLUE LADY".
                $builder->where('canonical_key', $this->normalizer->key($query->name));
            })
            ->when($query->minBooks !== null, fn ($builder) => $builder->where('book_count', '>=', $query->minBooks))
            // A year filter asks which drinks a period printed, so it is a
            // question about the mentions rather than about the drink's whole
            // span: a drink first printed in 1862 is still a 1930s drink if a
            // 1930s book prints it.
            ->when($query->fromYear !== null || $query->toYear !== null, function ($builder) use ($query) {
                $builder->whereHas('mentions', function ($mentions) use ($query) {
                    $mentions->when($query->fromYear !== null, fn ($q) => $q->where('book_year', '>=', $query->fromYear))
                        ->when($query->toYear !== null, fn ($q) => $q->where('book_year', '<=', $query->toYear));
                });
            })
            ->tap(fn ($builder) => $this->order($builder, $query->order))
            ->limit($limit);
    }

    /**
     * @param  Builder<Drink>  $builder
     */
    private function order($builder, string $order): void
    {
        // Ties broken by id so a repeated question gives a repeated answer;
        // a tally that reshuffles between askings reads as a corpus that
        // changed.
        match ($order) {
            'mentions' => $builder->orderByDesc('mention_count')->orderByDesc('book_count'),
            'earliest' => $builder->orderBy('first_year')->orderByDesc('book_count'),
            'latest' => $builder->orderByDesc('last_year')->orderByDesc('book_count'),
            default => $builder->orderByDesc('book_count')->orderByDesc('mention_count'),
        };

        $builder->orderBy('id');
    }

    /**
     * A few printed occurrences per drink, as their own passages would cite them.
     *
     * Rendered from the mention's chunk rather than from the columns beside it,
     * so a drink's citation is byte-identical to the one SearchTheBooks would
     * produce for that passage -- including the tilde on an interpolated page --
     * by construction rather than by a second formatter kept in step by hand.
     *
     * @param  list<int>  $drinkIds
     * @return Collection<int, list<string>>
     */
    private function citationsFor(array $drinkIds): Collection
    {
        $per = max(1, (int) config('books.drinks.survey.citations'));

        return DrinkMention::query()
            // forRetrieval() keeps the 4 KB vector and the stored tsvector out
            // of the hydrated chunks. Neither is read here, and a survey of 25
            // ubiquitous drinks would otherwise pull megabytes of them to
            // render a few dozen citations.
            ->with(['chunk' => fn ($chunk) => $chunk->forRetrieval()])
            ->whereIn('drink_id', $drinkIds)
            // Earliest first, so the citation a guest is offered is the oldest
            // printing rather than an arbitrary one.
            ->orderBy('book_year')
            ->orderBy('id')
            ->get()
            ->groupBy('drink_id')
            ->map(fn (Collection $mentions): array => $mentions
                ->unique('book_id')
                ->take($per)
                ->map(fn (DrinkMention $mention): string => $mention->citation())
                ->values()
                ->all());
    }
}
