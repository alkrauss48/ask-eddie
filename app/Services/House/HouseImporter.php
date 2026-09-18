<?php

namespace App\Services\House;

use App\Enums\HouseCollectionKind;
use App\Enums\HouseSourceType;
use App\Models\HouseBartender;
use App\Models\HouseChunk;
use App\Models\HouseCocktail;
use App\Models\HouseCollection;
use App\Models\HouseIngredient;
use App\Models\HouseRecipe;
use App\Models\HouseTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads the house export into the catalog tables, and renders it into chunks.
 *
 * Rendering is folded in here rather than given its own command. At 207 records
 * the whole pass is seconds, and content_hash plus renderer_version already
 * decide what gets re-rendered, so a separate command would only buy a second
 * thing to remember to run. That is a real difference from books, where
 * chunking is minutes of work over text that OCR produced hours earlier.
 *
 * The whole run is one transaction. Unlike books:embed -- where a nine-hour job
 * has to be resumable at batch granularity -- this finishes in seconds, and a
 * half-applied catalog is a corpus that answers questions with menus that are
 * missing drinks.
 */
class HouseImporter
{
    public function __construct(
        private readonly HouseExport $export,
        private readonly HouseRenderer $renderer,
    ) {}

    public function export(): HouseExport
    {
        return $this->export;
    }

    /**
     * Import everything, and render what the retriever needs.
     */
    public function import(bool $force = false): HouseImportReport
    {
        $report = new HouseImportReport;
        $started = microtime(true);

        DB::transaction(function () use ($report, $force): void {
            $recipes = $this->importRecipes($report);
            $this->importIngredients($report, $recipes);
            $bartenders = $this->importBartenders($report);
            $tags = $this->importTags($report);
            $cocktails = $this->importCocktails($report, $bartenders, $tags, $force);
            $this->importCollections($report, $cocktails);
            $this->renderChunks($report, $force);
        });

        $report->seconds = microtime(true) - $started;

        return $report;
    }

    /**
     * What an import would do, without writing anything.
     *
     * A real dry run rather than a printed intention: the work happens inside a
     * transaction that is always rolled back, so the counts are the counts the
     * live run will produce rather than an estimate of them.
     */
    public function plan(bool $force = false): HouseImportReport
    {
        $report = new HouseImportReport;
        $started = microtime(true);

        DB::beginTransaction();

        try {
            $recipes = $this->importRecipes($report);
            $this->importIngredients($report, $recipes);
            $bartenders = $this->importBartenders($report);
            $tags = $this->importTags($report);
            $cocktails = $this->importCocktails($report, $bartenders, $tags, $force);
            $this->importCollections($report, $cocktails);
            $this->renderChunks($report, $force);
        } finally {
            DB::rollBack();
        }

        $report->seconds = microtime(true) - $started;

        return $report;
    }

    /**
     * @return array<string, HouseRecipe> keyed by slug
     */
    private function importRecipes(HouseImportReport $report): array
    {
        $keyed = [];

        foreach ($this->export->recipes() as $record) {
            $recipe = HouseRecipe::firstOrNew(['slug' => (string) $record['slug']]);

            $recipe->fill([
                'name' => (string) $record['name'],
                'description' => $record['description'] ?? null,
                'ingredients' => array_values(array_map(strval(...), $record['ingredients'] ?? [])),
                'instructions' => $record['instructions'] ?? null,
                'notes' => $record['notes'] ?? null,
                'category' => $record['category'] ?? null,
                'url' => (string) $record['url'],
            ]);

            $this->save($report, 'recipes', $recipe);
            $keyed[$recipe->slug] = $recipe;
        }

        $this->prune($report, 'recipes', HouseRecipe::query(), array_keys($keyed));

        return $keyed;
    }

    /**
     * @param  array<string, HouseRecipe>  $recipes
     * @return array<string, HouseIngredient> keyed by slug
     */
    private function importIngredients(HouseImportReport $report, array $recipes): array
    {
        $keyed = [];

        foreach ($this->export->ingredients() as $record) {
            $ingredient = HouseIngredient::firstOrNew(['slug' => (string) $record['slug']]);
            $recipeSlug = $record['recipe_slug'] ?? null;

            if ($recipeSlug !== null && ! isset($recipes[$recipeSlug])) {
                throw new RuntimeException(
                    "Ingredient [{$record['slug']}] points at recipe [{$recipeSlug}], which the export does not contain."
                );
            }

            $ingredient->fill([
                'title' => (string) $record['title'],
                'group' => $record['group'] ?? null,
                'category_label' => $record['category'] ?? null,
                'subcategory_label' => $record['subcategory'] ?? null,
                'ingredient_type' => $record['type'] ?? null,
                'house_recipe_id' => $recipeSlug === null ? null : $recipes[$recipeSlug]->id,
                'url' => (string) $record['url'],
            ]);

            $this->save($report, 'ingredients', $ingredient);
            $keyed[$ingredient->slug] = $ingredient;
        }

        $this->prune($report, 'ingredients', HouseIngredient::query(), array_keys($keyed));

        return $keyed;
    }

    /**
     * @return array<string, HouseBartender> keyed by slug
     */
    private function importBartenders(HouseImportReport $report): array
    {
        $keyed = [];

        foreach ($this->export->bartenders() as $record) {
            $bartender = HouseBartender::firstOrNew(['slug' => (string) $record['slug']]);

            $bartender->fill([
                'name' => (string) $record['name'],
                'description' => $record['description'] ?? null,
                'birth_year' => $record['birth_year'] ?? null,
                'death_year' => $record['death_year'] ?? null,
                'url' => (string) $record['url'],
            ]);

            $this->save($report, 'bartenders', $bartender);
            $keyed[$bartender->slug] = $bartender;
        }

        $this->prune($report, 'bartenders', HouseBartender::query(), array_keys($keyed));

        return $keyed;
    }

    /**
     * @return array<string, HouseTag> keyed by "Category|Label"
     */
    private function importTags(HouseImportReport $report): array
    {
        $keyed = [];

        foreach ($this->export->tags() as $record) {
            $tag = HouseTag::firstOrNew([
                'category_label' => $record['category_label'],
                'label' => $record['label'],
            ]);

            $tag->fill(['order' => $record['order']]);

            $this->save($report, 'tags', $tag);
            $keyed[$this->tagKey($record['category_label'], $record['label'])] = $tag;
        }

        $present = array_map(
            fn (string $key): array => explode('|', $key, 2),
            array_keys($keyed),
        );

        $deleted = HouseTag::query()
            ->whereNotIn(DB::raw("category_label || '|' || label"), array_map(
                fn (array $pair): string => $pair[0].'|'.$pair[1],
                $present,
            ))
            ->delete();

        if ($deleted > 0) {
            $report->record('tags', 'deleted', $deleted);
        }

        return $keyed;
    }

    /**
     * @param  array<string, HouseBartender>  $bartenders
     * @param  array<string, HouseTag>  $tags
     * @return array<string, HouseCocktail> keyed by slug
     */
    private function importCocktails(HouseImportReport $report, array $bartenders, array $tags, bool $force): array
    {
        $keyed = [];

        foreach ($this->export->cocktails() as $record) {
            $slug = (string) $record['slug'];
            $cocktail = HouseCocktail::firstOrNew(['slug' => $slug]);

            // Over the record as the exporter wrote it, key order included.
            // json_encode of a decoded assoc array preserves that order, which
            // is why the source column is json rather than jsonb: jsonb would
            // canonicalize it and make this hash unstable across a round trip
            // that changed nothing.
            $hash = hash('sha256', (string) json_encode($record));
            $bartenderSlug = $record['bartender_slug'] ?? null;

            if ($bartenderSlug !== null && ! isset($bartenders[$bartenderSlug])) {
                throw new RuntimeException(
                    "Cocktail [{$slug}] is credited to bartender [{$bartenderSlug}], which the export does not contain."
                );
            }

            $unchanged = $cocktail->exists && $cocktail->content_hash === $hash && ! $force;

            $cocktail->fill([
                'title' => (string) $record['title'],
                'subtitle' => $record['subtitle'] ?? null,
                'description' => $record['description'] ?? null,
                'method' => $record['method'] ?? null,
                'served_in' => $record['served_in'] ?? null,
                'ice' => $record['ice'] ?? null,
                'has_straw' => (bool) ($record['has_straw'] ?? false),
                'servings' => $record['servings'] ?? null,
                'notes' => $record['notes'] ?? null,
                'house_bartender_id' => $bartenderSlug === null ? null : $bartenders[$bartenderSlug]->id,
                'variations' => $record['variations'] ?? [],
                'url' => (string) $record['url'],
                'source' => $record,
                'content_hash' => $hash,
            ]);

            $this->save($report, 'cocktails', $cocktail);
            $keyed[$slug] = $cocktail;

            // The ingredient lines and the tags are wholly derived from this
            // record, so an unchanged hash means they cannot have moved.
            // Rewriting them anyway would churn every pivot row on every run
            // and make "did anything change" unanswerable.
            if (! $unchanged) {
                $this->syncIngredients($report, $cocktail, $record);
                $this->syncTags($report, $cocktail, $record, $tags);
            }
        }

        $this->prune($report, 'cocktails', HouseCocktail::query(), array_keys($keyed));

        return $keyed;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function syncIngredients(HouseImportReport $report, HouseCocktail $cocktail, array $record): void
    {
        $cocktail->cocktailIngredients()->delete();
        $position = 0;
        $written = 0;

        foreach ($record['ingredients'] ?? [] as $entry) {
            $isText = ($entry['kind'] ?? null) === 'text';
            $slug = $entry['slug'] ?? null;

            $ingredient = $isText || $slug === null
                ? null
                : HouseIngredient::firstWhere('slug', $slug);

            if (! $isText && $ingredient === null) {
                throw new RuntimeException(
                    "Cocktail [{$cocktail->slug}] uses ingredient [{$slug}], which the export does not contain."
                );
            }

            $cocktail->cocktailIngredients()->create([
                'house_ingredient_id' => $ingredient?->id,
                'position' => $position++,
                'amount' => $isText ? null : ($entry['amount'] ?? null),
                'label' => $isText ? null : ($entry['label'] ?? null),
                // Set when, and only when, there is no catalog ingredient. A
                // row with neither names nothing, which --verify rejects.
                'free_text' => $isText ? (string) $entry['text'] : null,
            ]);

            $written++;
        }

        $report->record('cocktail ingredients', 'updated', $written);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, HouseTag>  $tags
     */
    private function syncTags(HouseImportReport $report, HouseCocktail $cocktail, array $record, array $tags): void
    {
        $ids = [];

        foreach ($record['tags'] ?? [] as $tag) {
            $key = $this->tagKey((string) $tag['category'], (string) $tag['label']);

            if (! isset($tags[$key])) {
                throw new RuntimeException(
                    "Cocktail [{$cocktail->slug}] carries tag [{$tag['label']}] in category [{$tag['category']}], which the export's tag list does not contain."
                );
            }

            $ids[] = $tags[$key]->id;
        }

        $cocktail->tags()->sync($ids);
        $report->record('cocktail tags', 'updated', count($ids));
    }

    /**
     * Menus and flights, which are one table because they are one structure.
     *
     * @param  array<string, HouseCocktail>  $cocktails
     */
    private function importCollections(HouseImportReport $report, array $cocktails): void
    {
        $seen = [];

        foreach ($this->export->menus() as $position => $record) {
            $collection = $this->saveCollection($report, HouseCollectionKind::Menu, $record, $position);
            $seen[] = [HouseCollectionKind::Menu->value, $collection->slug];

            $members = [];

            foreach ($record['sections'] ?? [] as $section) {
                foreach ($section['cocktail_slugs'] ?? [] as $slug) {
                    $members[] = [
                        'slug' => $slug,
                        'section_title' => $section['title'] ?? null,
                        'is_featured' => false,
                    ];
                }
            }

            foreach ($record['featured_slugs'] ?? [] as $slug) {
                $members[] = ['slug' => $slug, 'section_title' => null, 'is_featured' => true];
            }

            $this->syncMembers($report, $collection, $members, $cocktails);
        }

        foreach ($this->export->paths() as $position => $record) {
            $collection = $this->saveCollection($report, HouseCollectionKind::Path, $record, $position);
            $seen[] = [HouseCollectionKind::Path->value, $collection->slug];

            $this->syncMembers($report, $collection, array_map(
                fn (string $slug): array => ['slug' => $slug, 'section_title' => null, 'is_featured' => false],
                $record['cocktail_slugs'] ?? [],
            ), $cocktails);
        }

        $deleted = HouseCollection::query()
            ->whereNotIn(DB::raw("kind || '|' || slug"), array_map(
                fn (array $pair): string => $pair[0].'|'.$pair[1],
                $seen,
            ))
            ->delete();

        if ($deleted > 0) {
            $report->record('collections', 'deleted', $deleted);
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function saveCollection(
        HouseImportReport $report,
        HouseCollectionKind $kind,
        array $record,
        int $position,
    ): HouseCollection {
        $collection = HouseCollection::firstOrNew([
            'kind' => $kind,
            'slug' => (string) $record['slug'],
        ]);

        $collection->fill([
            'title' => (string) $record['title'],
            'subtitle' => $record['subtitle'] ?? null,
            'description' => $record['description'] ?? null,
            'position' => $position,
            'url' => (string) $record['url'],
        ]);

        $this->save($report, 'collections', $collection);

        return $collection;
    }

    /**
     * @param  list<array{slug: string, section_title: ?string, is_featured: bool}>  $members
     * @param  array<string, HouseCocktail>  $cocktails
     */
    private function syncMembers(HouseImportReport $report, HouseCollection $collection, array $members, array $cocktails): void
    {
        $desired = [];
        $position = 0;

        foreach ($members as $member) {
            if (! isset($cocktails[$member['slug']])) {
                throw new RuntimeException(sprintf(
                    'The %s [%s] lists cocktail [%s], which the export does not contain.',
                    $collection->kind->value,
                    $collection->slug,
                    $member['slug'],
                ));
            }

            $desired[] = [
                'house_cocktail_id' => $cocktails[$member['slug']]->id,
                'position' => $position++,
                'section_title' => $member['section_title'],
                'is_featured' => $member['is_featured'],
            ];
        }

        // Compared before writing rather than detached and re-attached blindly.
        // A menu's membership changes a few times a year, and rewriting these
        // rows on every run would make "nothing moved" a claim the run could
        // not actually support -- which is the one thing a second run is for.
        if ($this->currentMembers($collection) === $desired) {
            $report->record('collection cocktails', 'unchanged', count($desired));

            return;
        }

        $collection->cocktails()->detach();

        foreach ($desired as $member) {
            $collection->cocktails()->attach($member['house_cocktail_id'], [
                'position' => $member['position'],
                'section_title' => $member['section_title'],
                'is_featured' => $member['is_featured'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $report->record('collection cocktails', 'updated', count($desired));
    }

    /**
     * @return list<array{house_cocktail_id: int, position: int, section_title: ?string, is_featured: bool}>
     */
    private function currentMembers(HouseCollection $collection): array
    {
        if (! $collection->exists) {
            return [];
        }

        return DB::table('house_collection_cocktails')
            ->where('house_collection_id', $collection->id)
            ->orderBy('position')
            ->get(['house_cocktail_id', 'position', 'section_title', 'is_featured'])
            ->map(fn (object $row): array => [
                'house_cocktail_id' => (int) $row->house_cocktail_id,
                'position' => (int) $row->position,
                'section_title' => $row->section_title,
                'is_featured' => (bool) $row->is_featured,
            ])
            ->all();
    }

    /**
     * Render every chunkable record and write what actually moved.
     *
     * The comparison is on the rendered chunk's own content_hash rather than on
     * the source record's, because a chunk's text depends on more than its
     * record: a cocktail added to the summer menu has not changed, and its
     * passage has.
     */
    private function renderChunks(HouseImportReport $report, bool $force): void
    {
        $rendered = [];

        $cocktails = HouseCocktail::query()
            ->with(['bartender', 'tags', 'collections', 'cocktailIngredients.ingredient'])
            ->orderBy('slug')
            ->get();

        foreach ($cocktails as $cocktail) {
            $rendered[] = $this->renderer->renderCocktail($cocktail);
        }

        foreach (HouseRecipe::query()->orderBy('slug')->get() as $recipe) {
            $rendered[] = $this->renderer->renderRecipe($recipe);
        }

        foreach (HouseBartender::query()->orderBy('slug')->get() as $bartender) {
            $rendered[] = $this->renderer->renderBartender($bartender);
        }

        $collections = HouseCollection::query()
            ->with('cocktails')
            ->orderBy('kind')
            ->orderBy('position')
            ->get();

        foreach ($collections as $collection) {
            $rendered[] = $this->renderer->renderCollection($collection);
        }

        $keys = [];

        foreach ($rendered as $chunk) {
            $keys[] = $chunk->type->value.'|'.$chunk->slug;
            $this->writeChunk($report, $chunk, $force);
        }

        $deleted = HouseChunk::query()
            ->whereNotIn(DB::raw("source_type || '|' || source_slug"), $keys)
            ->delete();

        if ($deleted > 0) {
            $report->record('chunks', 'deleted', $deleted);
        }
    }

    private function writeChunk(HouseImportReport $report, RenderedChunk $rendered, bool $force): void
    {
        $chunk = HouseChunk::firstOrNew([
            'source_type' => $rendered->type,
            'source_slug' => $rendered->slug,
        ]);

        $hash = $rendered->contentHash();

        if ($chunk->exists
            && ! $force
            && $chunk->content_hash === $hash
            && $chunk->renderer_version === HouseRenderer::VERSION) {
            $report->record('chunks', 'unchanged');

            return;
        }

        $chunk->fill([
            'source_id' => $rendered->sourceId,
            'title' => $rendered->title,
            'subtitle' => $rendered->subtitle,
            'text' => $rendered->text,
            'keywords' => $rendered->keywords,
            'url' => $this->absoluteUrl($rendered->url),
            'content_hash' => $hash,
            'renderer_version' => HouseRenderer::VERSION,
            'is_indexable' => true,
            ...$this->renderer->measure($rendered),
        ]);

        $this->save($report, 'chunks', $chunk);
    }

    /**
     * A house citation is a link a guest can open, so it is absolute.
     */
    private function absoluteUrl(string $path): string
    {
        return str_starts_with($path, 'http')
            ? $path
            : (string) config('house.site_url').'/'.ltrim($path, '/');
    }

    private function save(HouseImportReport $report, string $entity, Model $model): void
    {
        if (! $model->exists) {
            $model->save();
            $report->record($entity, 'created');

            return;
        }

        if (! $model->isDirty()) {
            $report->record($entity, 'unchanged');

            return;
        }

        $model->save();
        $report->record($entity, 'updated');
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $slugs
     */
    private function prune(HouseImportReport $report, string $entity, $query, array $slugs): void
    {
        $deleted = $query->whereNotIn('slug', $slugs)->delete();

        if ($deleted > 0) {
            $report->record($entity, 'deleted', $deleted);
        }
    }

    private function tagKey(string $category, string $label): string
    {
        return $category.'|'.$label;
    }

    /**
     * Every way the imported catalog can be wrong, asked as a query.
     *
     * These are invariants rather than statistics: each one, if it holds, is
     * something the tools built on this catalog are allowed to assume. A
     * catalog that fails any of them still answers questions, just with drinks
     * nobody can find or menus that are missing a round.
     *
     * @return list<string> the failures, empty when the catalog is sound
     */
    public function verify(): array
    {
        $failures = [];

        $checksum = $this->export->checksum();
        $computed = $this->export->computeChecksum();

        if ($checksum !== null && $checksum !== $computed) {
            $failures[] = "the export's manifest records checksum {$checksum} but the files on disk hash to {$computed}";
        }

        foreach ($this->export->counts() as $name => $expected) {
            $actual = $this->export->actualCounts()[$name] ?? null;

            if ($actual !== null && $actual !== $expected) {
                $failures[] = "the manifest counts {$expected} {$name} but the export holds {$actual}";
            }
        }

        $tables = [
            'cocktails' => HouseCocktail::query()->count(),
            'ingredients' => HouseIngredient::query()->count(),
            'recipes' => HouseRecipe::query()->count(),
            'bartenders' => HouseBartender::query()->count(),
        ];

        foreach ($tables as $name => $imported) {
            $expected = $this->export->counts()[$name] ?? null;

            if ($expected !== null && $imported !== $expected) {
                $failures[] = "the export holds {$expected} {$name} but {$imported} were imported";
            }
        }

        // A line that names neither a catalog ingredient nor a string of text
        // is a pour of nothing, and it renders as a blank line in a recipe.
        $empty = DB::table('house_cocktail_ingredients')
            ->whereNull('house_ingredient_id')
            ->whereNull('free_text')
            ->count();

        if ($empty > 0) {
            $failures[] = "{$empty} cocktail ingredient row(s) name neither an ingredient nor any text";
        }

        // The invariant that is specific to Sasha's job. A cocktail with no
        // ingredients or no tags is invisible to every structured filter --
        // unrecommendable by anybody, silently, while looking perfectly fine in
        // the table.
        $ingredientless = HouseCocktail::query()->whereDoesntHave('cocktailIngredients')->pluck('slug');

        if ($ingredientless->isNotEmpty()) {
            $failures[] = sprintf(
                '%d cocktail(s) have no ingredients and cannot be built: %s',
                $ingredientless->count(),
                $ingredientless->take(5)->implode(', '),
            );
        }

        $untagged = HouseCocktail::query()->whereDoesntHave('tags')->pluck('slug');

        if ($untagged->isNotEmpty()) {
            $failures[] = sprintf(
                '%d cocktail(s) carry no tags and cannot be found by any facet: %s',
                $untagged->count(),
                $untagged->take(5)->implode(', '),
            );
        }

        $emptyCollections = HouseCollection::query()->whereDoesntHave('cocktails')->pluck('slug');

        if ($emptyCollections->isNotEmpty()) {
            $failures[] = sprintf(
                '%d collection(s) list no cocktails: %s',
                $emptyCollections->count(),
                $emptyCollections->implode(', '),
            );
        }

        $failures = [...$failures, ...$this->verifyChunks()];

        return $failures;
    }

    /**
     * Assert that every chunk is still exactly what the renderer would produce.
     *
     * This is the house equivalent of `books:chunks --verify` re-slicing the
     * stream: there is no byte offset to check here, so the check is that a
     * re-render reproduces the stored hash. A chunk that fails it is citing a
     * URL for text the site no longer has.
     *
     * @return list<string>
     */
    private function verifyChunks(): array
    {
        $failures = [];

        $expected = HouseCocktail::query()->count()
            + HouseRecipe::query()->count()
            + HouseBartender::query()->count()
            + HouseCollection::query()->count();

        $actual = HouseChunk::query()->count();

        if ($actual !== $expected) {
            $failures[] = "{$expected} record(s) are chunkable but {$actual} chunk(s) exist";
        }

        $stale = [];

        $cocktails = HouseCocktail::query()
            ->with(['bartender', 'tags', 'collections', 'cocktailIngredients.ingredient'])
            ->get();

        foreach ($cocktails as $cocktail) {
            $stale = [...$stale, ...$this->compare($this->renderer->renderCocktail($cocktail))];
        }

        foreach (HouseRecipe::query()->get() as $recipe) {
            $stale = [...$stale, ...$this->compare($this->renderer->renderRecipe($recipe))];
        }

        foreach (HouseBartender::query()->get() as $bartender) {
            $stale = [...$stale, ...$this->compare($this->renderer->renderBartender($bartender))];
        }

        foreach (HouseCollection::query()->with('cocktails')->get() as $collection) {
            $stale = [...$stale, ...$this->compare($this->renderer->renderCollection($collection))];
        }

        if ($stale !== []) {
            $failures[] = sprintf(
                '%d chunk(s) no longer match a re-render: %s',
                count($stale),
                implode(', ', array_slice($stale, 0, 5)),
            );
        }

        // The stored hash against the row's own columns. This is what
        // house:embed reads to decide whether a vector is current, so a row
        // whose hash no longer describes its own text is one that will never be
        // re-embedded however far its passage has drifted.
        $mismatched = HouseChunk::query()
            ->forRetrieval()
            ->get()
            ->filter(fn (HouseChunk $chunk): bool => $chunk->content_hash !== $chunk->currentContentHash())
            ->map(fn (HouseChunk $chunk): string => $chunk->source_type->value.':'.$chunk->source_slug)
            ->values();

        if ($mismatched->isNotEmpty()) {
            $failures[] = sprintf(
                '%d chunk(s) carry a content hash that does not describe their own text: %s',
                $mismatched->count(),
                $mismatched->take(5)->implode(', '),
            );
        }

        $outdated = HouseChunk::query()->where('renderer_version', '<', HouseRenderer::VERSION)->count();

        if ($outdated > 0) {
            $failures[] = "{$outdated} chunk(s) were rendered by an older renderer version";
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    private function compare(RenderedChunk $rendered): array
    {
        $chunk = HouseChunk::query()
            ->where('source_type', $rendered->type)
            ->where('source_slug', $rendered->slug)
            ->first();

        if ($chunk === null) {
            return [$rendered->type->value.':'.$rendered->slug.' (missing)'];
        }

        // Against the hash recomputed from the row's own columns rather than
        // against the stored content_hash. Comparing two stored values would
        // only prove they agree with each other: a chunk whose text was edited
        // in place still carries the hash it was written with, so the check
        // would pass while the passage cited a URL for words the site has not
        // got. The stored hash is checked separately, below.
        return $chunk->currentContentHash() === $rendered->contentHash()
            ? []
            : [$rendered->type->value.':'.$rendered->slug];
    }

    /**
     * How many chunks each kind of record contributes.
     *
     * @return array<string, int>
     */
    public function chunkCounts(): array
    {
        $counts = HouseChunk::query()
            ->reorder()
            ->selectRaw('source_type, count(*) as total')
            ->groupBy('source_type')
            ->pluck('total', 'source_type');

        $rows = [];

        foreach (HouseSourceType::cases() as $type) {
            $rows[$type->value] = (int) ($counts[$type->value] ?? 0);
        }

        return $rows;
    }
}
