<?php

namespace App\Services\Retrieval;

use App\Models\Book;
use App\Models\Drink;
use App\Models\DrinkMention;
use App\Services\Books\DrinkNameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
 *
 * Two things it will not do. It counts only rows DrinkClassifier marked
 * countable, so the 7,011 single-book OCR artefacts are out of every tally
 * without being out of the database. And when a question carries year bounds it
 * counts inside them rather than filtering by them -- see DrinkTally.
 */
class DrinkSurveyor
{
    public function __construct(private readonly DrinkNameNormalizer $normalizer) {}

    /**
     * @return Collection<int, DrinkSummary>
     */
    public function survey(DrinkQuery $query): Collection
    {
        [$drinks, $tallies] = $query->isWindowed()
            ? $this->windowed($query)
            : $this->corpusWide($query);

        if ($drinks->isEmpty()) {
            return new Collection;
        }

        $mentions = $this->mentionsFor($drinks->modelKeys(), $query);

        return $drinks->map(function (Drink $drink) use ($tallies, $mentions, $query): DrinkSummary {
            /** @var Collection<int, DrinkMention> $own */
            $own = $mentions->get($drink->id, new Collection);

            return DrinkSummary::fromDrink(
                $drink,
                $tallies[$drink->id] ?? DrinkTally::fromDrink($drink),
                $this->spellings($drink, $own, $query),
                $this->citations($own),
            );
        })->values();
    }

    public function coverage(?DrinkQuery $query = null): DrinkCoverage
    {
        $window = $query !== null && $query->isWindowed() ? $query : null;

        $perBook = DrinkMention::query()
            ->when($window !== null, fn ($builder) => $this->boundYears($builder, $window))
            ->selectRaw('book_id, count(*) as total')
            ->groupBy('book_id')
            ->pluck('total')
            ->map(fn ($total): int => (int) $total)
            ->all();

        return new DrinkCoverage(
            booksCounted: count($perBook),
            booksTotal: $this->booksTotal($window),
            drinkCount: Drink::query()->where('is_countable', true)->count(),
            booksCarrying: DrinkCoverage::carrying($perBook),
            window: $window?->yearRange(),
            shelfOrdered: $query !== null && in_array($query->order, ['earliest', 'latest'], true),
            rowsTallied: Drink::query()->count(),
        );
    }

    /**
     * How many books the question could possibly have drawn on.
     *
     * For a windowed survey that is the books the window holds, not the whole
     * shelf -- "3 of 6" is the honest denominator for the 1860s, and "3 of 102"
     * would read as a drink almost nobody printed.
     */
    private function booksTotal(?DrinkQuery $window): int
    {
        return Book::query()
            ->when($window !== null, fn ($builder) => $this->boundYears($builder, $window, 'year'))
            ->count();
    }

    /**
     * The corpus-wide path: the materialized columns, read exactly as stored.
     *
     * @return array{0: Collection<int, Drink>, 1: array<int, DrinkTally>}
     */
    private function corpusWide(DrinkQuery $query): array
    {
        $drinks = $this->base($query)
            ->when($query->minBooks !== null, fn ($builder) => $builder->where('book_count', '>=', $query->minBooks))
            ->tap(fn ($builder) => $this->order($builder, $query->order, 'book_count', 'mention_count', 'first_year', 'last_year'))
            ->limit($this->limit($query))
            ->get();

        return [$drinks, []];
    }

    /**
     * The windowed path: count the mentions the window actually holds.
     *
     * Ordering happens here, on the windowed numbers, which is the whole point
     * -- ranking a filtered set by the corpus-wide column returns the corpus-wide
     * answer with a year on it.
     *
     * @return array{0: Collection<int, Drink>, 1: array<int, DrinkTally>}
     */
    private function windowed(DrinkQuery $query): array
    {
        $rows = DB::table('drink_mentions as m')
            ->join('drinks as d', 'd.id', '=', 'm.drink_id')
            ->where('d.is_countable', true)
            ->when($query->name !== null && $query->name !== '', fn ($builder) => $builder
                ->where('d.canonical_key', $this->normalizer->key($query->name)))
            ->tap(fn ($builder) => $this->boundYears($builder, $query, 'm.book_year'))
            ->groupBy('m.drink_id')
            ->select('m.drink_id')
            ->selectRaw('count(distinct m.book_id) as book_count')
            ->selectRaw('count(*) as mention_count')
            ->selectRaw('min(m.book_year) as first_year')
            ->selectRaw('max(m.book_year) as last_year')
            ->when($query->minBooks !== null, fn ($builder) => $builder
                ->havingRaw('count(distinct m.book_id) >= ?', [$query->minBooks]))
            ->tap(fn ($builder) => $this->order($builder, $query->order, 'book_count', 'mention_count', 'first_year', 'last_year', 'm.drink_id'))
            ->limit($this->limit($query))
            ->get();

        $tallies = [];

        foreach ($rows as $row) {
            $tallies[(int) $row->drink_id] = new DrinkTally(
                (int) $row->book_count,
                (int) $row->mention_count,
                $row->first_year === null ? null : (int) $row->first_year,
                $row->last_year === null ? null : (int) $row->last_year,
            );
        }

        $ids = array_keys($tallies);

        // Hydrated separately and reordered in PHP: whereIn cannot preserve the
        // ranking the aggregate just established, and a second ORDER BY over the
        // same expressions would be a copy of it kept in step by hand.
        $drinks = Drink::query()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Drink $drink): int => (int) array_search($drink->id, $ids, true))
            ->values();

        return [$drinks, $tallies];
    }

    /**
     * @return Builder<Drink>
     */
    private function base(DrinkQuery $query)
    {
        return Drink::query()
            // The tally counts drinks, and DrinkClassifier decides which rows
            // are one. Nothing is deleted to make this true.
            ->where('is_countable', true)
            ->when($query->name !== null && $query->name !== '', function ($builder) use ($query) {
                // Matched through the same fold the corpus was stored under, so
                // a guest writing "blue lady" finds "BLUE LADY".
                $builder->where('canonical_key', $this->normalizer->key($query->name));
            });
    }

    private function limit(DrinkQuery $query): int
    {
        return max(1, min($query->limit, (int) config('books.drinks.survey.max_limit')));
    }

    /**
     * Apply the query's year bounds to whichever builder is counting.
     *
     * @template TBuilder
     *
     * @param  TBuilder  $builder
     * @return TBuilder
     */
    private function boundYears($builder, DrinkQuery $query, string $column = 'book_year')
    {
        if ($query->fromYear !== null) {
            $builder->where($column, '>=', $query->fromYear);
        }

        if ($query->toYear !== null) {
            $builder->where($column, '<=', $query->toYear);
        }

        return $builder;
    }

    /**
     * Ties broken by id so a repeated question gives a repeated answer; a tally
     * that reshuffles between askings reads as a corpus that changed.
     */
    private function order(
        $builder,
        string $order,
        string $books,
        string $mentions,
        string $first,
        string $last,
        string $tiebreak = 'id',
    ): void {
        match ($order) {
            'mentions' => $builder->orderByDesc($mentions)->orderByDesc($books),
            'earliest' => $builder->orderBy($first)->orderByDesc($books),
            'latest' => $builder->orderByDesc($last)->orderByDesc($books),
            default => $builder->orderByDesc($books)->orderByDesc($mentions),
        };

        $builder->orderBy($tiebreak);
    }

    /**
     * Every mention that the question's window admits, grouped by drink.
     *
     * One query serves both the citations and the spellings, and both are bound
     * by the window for the same reason: asked what the sixties printed, Eddie
     * must not answer with an 1899 page. A citation outside the window is a true
     * sentence about the wrong books.
     *
     * @param  list<int>  $drinkIds
     * @return Collection<int, Collection<int, DrinkMention>>
     */
    private function mentionsFor(array $drinkIds, DrinkQuery $query): Collection
    {
        return DrinkMention::query()
            // forRetrieval() keeps the 4 KB vector and the stored tsvector out
            // of the hydrated chunks. Neither is read here, and a survey of 25
            // ubiquitous drinks would otherwise pull megabytes of them to
            // render a few dozen citations.
            ->with(['chunk' => fn ($chunk) => $chunk->forRetrieval()])
            ->whereIn('drink_id', $drinkIds)
            ->tap(fn ($builder) => $this->boundYears($builder, $query))
            // Earliest first, so the citation a guest is offered is the oldest
            // printing rather than an arbitrary one.
            ->orderBy('book_year')
            ->orderBy('id')
            ->get()
            ->groupBy('drink_id');
    }

    /**
     * @param  Collection<int, DrinkMention>  $mentions
     * @return list<string>
     */
    private function citations(Collection $mentions): array
    {
        $per = max(1, (int) config('books.drinks.survey.citations'));

        return $mentions
            ->unique('book_id')
            ->take($per)
            ->map(fn (DrinkMention $mention): string => $mention->citation())
            ->values()
            ->all();
    }

    /**
     * The other spellings, most printed first.
     *
     * Corpus-wide, this is drinks.aliases -- the receipt DrinkExtractor
     * recomputes wholesale, and the authoritative record of what a merge folded
     * together. Inside a window it has to be counted from the window's own
     * mentions instead: a spelling only a 1937 book used is not an answer to a
     * question about the 1860s, and aliases cannot say which years it came from.
     *
     * @param  Collection<int, DrinkMention>  $mentions
     * @return list<string>
     */
    private function spellings(Drink $drink, Collection $mentions, DrinkQuery $query): array
    {
        if (! $query->isWindowed() || $mentions->isEmpty()) {
            return $drink->otherSpellings();
        }

        return $mentions
            ->countBy('raw_heading')
            ->sortDesc()
            ->keys()
            ->reject(fn (string $spelling): bool => $spelling === $drink->canonical_name)
            ->values()
            ->all();
    }
}
