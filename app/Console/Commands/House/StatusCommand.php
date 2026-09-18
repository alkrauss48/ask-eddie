<?php

namespace App\Console\Commands\House;

use App\Enums\HouseSourceType;
use App\Models\HouseBartender;
use App\Models\HouseChunk;
use App\Models\HouseCocktail;
use App\Models\HouseCollection;
use App\Models\HouseIngredient;
use App\Models\HouseRecipe;
use App\Services\House\HouseEmbedder;
use App\Services\House\HouseExport;
use App\Services\House\HouseRenderer;
use Illuminate\Console\Command;

/**
 * What the house corpus currently holds, and what still needs doing.
 *
 * Deliberately has no --verify of its own. A third verifier that unioned the
 * other two would drift out of sync with them the first time an invariant was
 * added to only one, and the drift would be invisible -- a green run that
 * checked less than it used to. This prints counts and points at the two real
 * verifiers instead.
 */
class StatusCommand extends Command
{
    protected $signature = 'house:status';

    protected $description = 'Show what the house catalog holds and how much of it is embedded';

    public function handle(HouseExport $export, HouseEmbedder $embedder): int
    {
        $cocktails = HouseCocktail::query()->count();

        if ($cocktails === 0) {
            $this->warn('Nothing imported yet. Run `house:import`.');

            return self::FAILURE;
        }

        $counts = $export->isAvailable() ? $export->counts() : [];

        $this->newLine();
        $this->table(['Table', 'Imported', 'In export'], [
            ['cocktails', (string) $cocktails, $this->expected($counts, 'cocktails')],
            ['ingredients', (string) HouseIngredient::query()->count(), $this->expected($counts, 'ingredients')],
            ['recipes', (string) HouseRecipe::query()->count(), $this->expected($counts, 'recipes')],
            ['bartenders', (string) HouseBartender::query()->count(), $this->expected($counts, 'bartenders')],
            ['menus', (string) HouseCollection::query()->where('kind', 'menu')->count(), $this->expected($counts, 'menus')],
            ['flights', (string) HouseCollection::query()->where('kind', 'path')->count(), $this->expected($counts, 'paths')],
        ]);

        $this->table(
            ['Chunks', 'Total', 'Embedded'],
            array_map(fn (HouseSourceType $type): array => $this->chunkRow($type), HouseSourceType::cases()),
        );

        $total = HouseChunk::query()->count();
        $embedded = HouseChunk::query()->whereNotNull('embedding')->count();
        $pending = $embedder->pending()->count();

        $this->line(sprintf(
            '%d chunk(s) at renderer v%d, %d embedded by %s, %s.',
            $total,
            HouseRenderer::VERSION,
            $embedded,
            $embedder->model(),
            $pending > 0 ? "<fg=yellow>{$pending} pending</>" : '0 pending',
        ));

        $stale = HouseChunk::query()->where('renderer_version', '<', HouseRenderer::VERSION)->count();

        if ($stale > 0) {
            $this->line("<fg=yellow>{$stale} chunk(s) were rendered by an older renderer; run `house:import`.</>");
        }

        $this->newLine();
        $this->line('<fg=gray>Check the corpus with `house:import --verify` and `house:embed --verify`.</>');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function chunkRow(HouseSourceType $type): array
    {
        $total = HouseChunk::query()->where('source_type', $type)->count();
        $embedded = HouseChunk::query()->where('source_type', $type)->whereNotNull('embedding')->count();

        return [
            $type->value,
            (string) $total,
            $embedded === $total ? (string) $embedded : "<fg=yellow>{$embedded}</>",
        ];
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function expected(array $counts, string $key): string
    {
        return isset($counts[$key]) ? (string) $counts[$key] : '<fg=gray>—</>';
    }
}
