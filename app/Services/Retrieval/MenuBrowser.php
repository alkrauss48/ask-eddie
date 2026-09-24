<?php

namespace App\Services\Retrieval;

use App\Models\HouseCocktail;
use App\Models\HouseCollection;
use App\Models\HouseIngredient;
use App\Models\HouseTag;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Which drinks the house pours, filtered exactly.
 *
 * HouseRetriever finds the passages most like a question, which is the wrong
 * instrument for "I don't like whiskey, what else have you got". A vector
 * answers a negation plausibly and wrongly -- an embedding of "not whiskey"
 * sits next to the whiskey drinks -- and a model handed six near-misses will
 * name one anyway. So this is plain SQL over the facet tables: no vector, no
 * fusion, no reranking, and every row it returns is a drink that is genuinely
 * on a menu.
 *
 * Ordering is by how many curated lists a drink appears on, then by title, then
 * by id. A drink the house put on a menu and in a flight is one it leans on,
 * which is a better answer to "what's good?" than whichever title sorts first;
 * the title and the id after it are there so a repeated question gives a
 * repeated answer.
 */
class MenuBrowser
{
    /**
     * The ingredient catalog, read once per browser and folded for matching.
     *
     * @var list<array{id: int, title: string, exact: list<string>, words: list<string>}>|null
     */
    private ?array $catalog = null;

    /**
     * The drinks that fit, best first.
     *
     * @return Collection<int, CocktailSummary>
     */
    public function browse(MenuQuery $query): Collection
    {
        return $this->filtered($query)
            // Three relations, eager loaded. A browse of eight drinks that
            // reached for its ingredient lines per row would cost twenty-five
            // queries to answer "what's good".
            ->with(['cocktailIngredients.ingredient', 'tags', 'collections'])
            ->withCount('collections')
            ->orderByDesc('collections_count')
            ->orderBy('title')
            ->orderBy('id')
            ->limit(max(1, $query->limit))
            ->get()
            ->map(CocktailSummary::fromCocktail(...))
            ->values();
    }

    /**
     * How many drinks fit in total, before the limit.
     *
     * The denominator, and it travels with the answer for the same reason
     * DrinkCoverage's does: "here are eight" reads as the whole list unless
     * something says there were thirty-one.
     */
    public function matching(MenuQuery $query): int
    {
        return $this->filtered($query)->count();
    }

    /**
     * Whether the catalog has been imported at all.
     *
     * An empty house and a question nothing matched are different facts, and
     * collapsing them would have Sasha tell a guest the house pours nothing.
     */
    public function poursAnything(): bool
    {
        return HouseCocktail::query()->exists();
    }

    /**
     * The values in this question that name nothing the house has.
     *
     * A guest asking for scotch gets no rows, and without this the tool reports
     * that as "nothing on the menus fits" -- a true sentence about the wrong
     * thing, since the house has no Scotch tag at all and does pour whiskey.
     * Naming the unrecognised value lets Sasha say which word she did not know.
     *
     * @return list<string>
     */
    public function unrecognised(MenuQuery $query): array
    {
        $unknown = [];

        foreach ([$query->required(), $query->excluded()] as $group) {
            foreach ($group as $category => $labels) {
                $known = $this->labelsIn($category);

                foreach ($labels as $label) {
                    if (! in_array(mb_strtolower($label), $known, true)) {
                        $unknown[] = $label;
                    }
                }
            }
        }

        foreach ([...$query->withIngredients, ...$query->withoutIngredients] as $ingredient) {
            if (! $this->ingredientExists($ingredient)) {
                $unknown[] = $ingredient;
            }
        }

        if ($query->menu !== null && ! $this->collectionExists($query->menu)) {
            $unknown[] = $query->menu;
        }

        return array_values(array_unique($unknown));
    }

    /**
     * The ingredient words that named no entry outright, and what they were read as.
     *
     * A guest says "curaçao" and the catalog says "Dry Curaçao". Matching on
     * whole words finds it, but Sasha should then name the bottle the house
     * actually pours rather than a generic curaçao it does not -- and a word as
     * broad as "orange" should be visible as the six entries it caught rather
     * than silently standing for all of them.
     *
     * @return array<string, list<string>>
     */
    public function interpreted(MenuQuery $query): array
    {
        $readAs = [];

        foreach ([...$query->withIngredients, ...$query->withoutIngredients] as $ingredient) {
            if ($this->exactIngredients($ingredient) !== []) {
                continue;
            }

            $titles = array_column($this->wordIngredients($ingredient), 'title');

            if ($titles !== []) {
                $readAs[$ingredient] = $titles;
            }
        }

        return $readAs;
    }

    /**
     * Every facet the house tags along, for a tool description or an error.
     *
     * Read from the table rather than hard-coded, because the site owns this
     * vocabulary: a tenth category added over there should reach Sasha without
     * a constant being edited here.
     *
     * @return array<string, list<string>>
     */
    public function facets(): array
    {
        return HouseTag::query()
            ->orderBy('category_label')
            ->orderBy('order')
            ->orderBy('label')
            ->get()
            ->groupBy('category_label')
            ->map(fn (Collection $tags): array => $tags
                ->map(fn (HouseTag $tag): string => $tag->label)
                ->values()
                ->all())
            ->all();
    }

    /**
     * The filtered set, without ordering, loading or a limit.
     *
     * Shared by browse() and matching() so the count is provably a count of the
     * same rows: two builders kept in step by hand would drift the first time a
     * facet was added to one of them.
     *
     * @return Builder<HouseCocktail>
     */
    private function filtered(MenuQuery $query): Builder
    {
        $builder = HouseCocktail::query();

        // One whereHas per category, so categories are ANDed and the labels
        // inside a category are ORed: "a gin or vodka drink that is also
        // citrusy" is the question a guest is asking, never "a drink that is
        // both gin and vodka".
        foreach ($query->required() as $category => $labels) {
            $builder->whereHas('tags', fn (Builder $tags) => $this->whereLabelIn($tags, $category, $labels));
        }

        foreach ($query->excluded() as $category => $labels) {
            $builder->whereDoesntHave('tags', fn (Builder $tags) => $this->whereLabelIn($tags, $category, $labels));
        }

        foreach ($query->withIngredients as $ingredient) {
            $builder->whereHas('ingredients', fn (Builder $ingredients) => $ingredients->whereKey($this->ingredientIdsFor($ingredient)));
        }

        foreach ($query->withoutIngredients as $ingredient) {
            $builder->whereDoesntHave('ingredients', fn (Builder $ingredients) => $ingredients->whereKey($this->ingredientIdsFor($ingredient)));
        }

        if ($query->menu !== null && trim($query->menu) !== '') {
            $builder->whereHas('collections', fn (Builder $collections) => $this->whereCollectionIs($collections, $query->menu));
        }

        return $builder;
    }

    /**
     * @param  Builder<HouseTag>  $tags
     * @param  list<string>  $labels
     */
    private function whereLabelIn(Builder $tags, string $category, array $labels): void
    {
        // Folded rather than matched exactly: the model writes "whiskey" as
        // often as it writes "Whiskey", and a facet filter that is silently
        // case-sensitive answers a real question with an empty list.
        $tags->where('category_label', $category)
            ->whereIn(
                $this->lower('label'),
                array_map(mb_strtolower(...), $labels),
            );
    }

    /**
     * The catalog entries an ingredient word names: exactly if it can, by whole words if not.
     *
     * Exactly first, by slug, by the bottle, or by the style it is grouped
     * under, because a guest says "Smith and Cross" (the title), a menu says
     * "Jamaican Rum" (the group), and a tool call may carry either or the slug
     * between them. Matching the group is what makes "with Jamaican rum" find
     * every bottle of that style rather than one -- and matching it exactly is
     * what keeps it from widening to the overproof Jamaican rums too.
     *
     * By whole words only when nothing matched exactly. Most of the catalog has
     * no group, so an exact match alone leaves "curaçao" unable to find "Dry
     * Curaçao" and "bitters" unable to find any of them, and the tool then tells
     * Sasha the house has nothing filed under a bottle it pours in eight drinks.
     * Every word of the needle must be a whole word of the title or group, with
     * accents folded so "curacao" typed plainly lands too. interpreted() names
     * what this caught, so a partial word is never silently read as a bottle.
     *
     * This happens at question time and is stored nowhere, which is what
     * separates it from the import-time fuzzy linking .ai/rules/house.md refuses.
     *
     * @return list<int>
     */
    private function ingredientIdsFor(string $needle): array
    {
        $exact = $this->exactIngredients($needle);

        return array_column($exact !== [] ? $exact : $this->wordIngredients($needle), 'id');
    }

    /**
     * @return list<array{id: int, title: string, exact: list<string>, words: list<string>}>
     */
    private function exactIngredients(string $needle): array
    {
        $needle = mb_strtolower(trim($needle));

        return array_values(array_filter(
            $this->catalog(),
            fn (array $entry): bool => in_array($needle, $entry['exact'], true),
        ));
    }

    /**
     * @return list<array{id: int, title: string, exact: list<string>, words: list<string>}>
     */
    private function wordIngredients(string $needle): array
    {
        $words = $this->words($needle);

        if ($words === []) {
            return [];
        }

        return array_values(array_filter(
            $this->catalog(),
            fn (array $entry): bool => array_diff($words, $entry['words']) === [],
        ));
    }

    /**
     * Every ingredient, folded once.
     *
     * A hundred and thirty rows, so reading them into memory is cheaper than
     * teaching SQL to fold accents -- which Postgres cannot do without the
     * unaccent extension -- and it is read at most once per question.
     *
     * @return list<array{id: int, title: string, exact: list<string>, words: list<string>}>
     */
    private function catalog(): array
    {
        return $this->catalog ??= HouseIngredient::query()
            ->orderBy('title')
            ->orderBy('id')
            ->get(['id', 'slug', 'title', 'group'])
            ->map(fn (HouseIngredient $ingredient): array => [
                'id' => $ingredient->id,
                'title' => $ingredient->title,
                'exact' => array_values(array_filter(array_map(
                    fn (?string $value): ?string => $value === null ? null : mb_strtolower($value),
                    [$ingredient->slug, $ingredient->title, $ingredient->group],
                ))),
                'words' => array_values(array_unique([
                    ...$this->words($ingredient->title),
                    ...$this->words((string) $ingredient->group),
                ])),
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function words(string $value): array
    {
        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/', mb_strtolower(Str::ascii($value))) ?: [],
            fn (string $word): bool => $word !== '',
        ));
    }

    /**
     * @param  Builder<HouseCollection>  $collections
     */
    private function whereCollectionIs(Builder $collections, string $needle): void
    {
        $needle = mb_strtolower(trim($needle));

        // Menus and flights share a table on purpose, and a guest saying "off
        // the summer menu" and one saying "from the Shaman flight" are asking
        // the same kind of question, so neither kind is excluded here.
        $collections->where(function (Builder $match) use ($needle): void {
            $match->where($this->lower('house_collections.slug'), $needle)
                ->orWhere($this->lower('house_collections.title'), $needle);
        });
    }

    /**
     * The labels one category holds, folded for comparison.
     *
     * @return list<string>
     */
    private function labelsIn(string $category): array
    {
        return HouseTag::query()
            ->where('category_label', $category)
            ->pluck('label')
            ->map(fn (string $label): string => mb_strtolower($label))
            ->values()
            ->all();
    }

    private function ingredientExists(string $needle): bool
    {
        return $this->ingredientIdsFor($needle) !== [];
    }

    private function collectionExists(string $needle): bool
    {
        return HouseCollection::query()
            ->where(fn (Builder $match) => $this->whereCollectionIs($match, $needle))
            ->exists();
    }

    /**
     * lower(column) as an expression the query builder will not quote as a value.
     *
     * The identifier is quoted per segment, and that is not tidiness: the
     * ingredient catalog has a column called "group", which is a reserved word,
     * and an unquoted lower(group) is a syntax error rather than an empty
     * result -- so it fails through the tool's catch-all and reads as an outage.
     */
    private function lower(string $column): Expression
    {
        $quoted = implode('.', array_map(
            fn (string $segment): string => '"'.str_replace('"', '', $segment).'"',
            explode('.', $column),
        ));

        return DB::raw("lower({$quoted})");
    }
}
