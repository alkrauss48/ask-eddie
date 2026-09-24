<?php

namespace App\Console\Commands\House;

use App\Services\House\HouseImporter;
use App\Services\House\HouseImportReport;
use App\Services\House\HouseRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Loads the house export into the catalog tables and renders it into chunks.
 *
 * Both halves in one command, which is a deliberate departure from
 * books:import / books:chunk. There, chunking is a separate pass over text that
 * OCR spent hours producing; here the whole corpus is 207 records and under a
 * megabyte of JSON, so the render is seconds and content_hash already decides
 * what actually gets rewritten. A second command would only be a second thing
 * to forget to run.
 *
 * Nothing here calls a model or reaches the network: the export is a file on
 * disk, and every decision this makes is deterministic. That is what lets it
 * be verified on its own, before anything AI-facing depends on it.
 */
class ImportCommand extends Command
{
    protected $signature = 'house:import
        {--force : Re-write every record and re-render every chunk}
        {--dry-run : Report what would change without writing anything}
        {--verify : Check the imported catalog against its invariants and exit}';

    protected $description = 'Import the-krauss-haus export into the house catalog and render it into chunks';

    public function handle(HouseImporter $importer): int
    {
        if ($this->option('verify')) {
            return $this->verify($importer);
        }

        $missing = $importer->export()->missingFiles();

        if ($missing !== []) {
            $this->error('The house export is incomplete on '.$importer->export()->sourceDirectory().'.');
            $this->line('<fg=gray>Missing: '.implode(', ', $missing).'</>');
            $this->line('<fg=gray>Run `house:fetch` to download it from the site, `npm run export:data` in the-krauss-haus, or set HOUSE_PATH.</>');

            return self::FAILURE;
        }

        // A second concurrent run would prune the rows the first is still
        // writing, so it is turned away rather than allowed to interleave. The
        // TTL is short because this run is seconds, not hours.
        $lock = Cache::lock('house:import', 10 * 60);

        if (! $lock->get()) {
            $this->error('Another house:import run is already in progress.');

            return self::FAILURE;
        }

        try {
            return $this->import($importer);
        } finally {
            $lock->release();
        }
    }

    private function import(HouseImporter $importer): int
    {
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $this->newLine();
        $this->line(sprintf(
            '<fg=gray>%s, generated %s, checksum %s…</>',
            $importer->export()->sourceDirectory(),
            $importer->export()->generatedAt() ?? 'unknown',
            substr((string) $importer->export()->checksum(), 0, 12),
        ));

        try {
            $report = $dryRun ? $importer->plan($force) : $importer->import($force);
        } catch (Throwable $exception) {
            $this->newLine();
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Table', 'New', 'Changed', 'Unchanged', 'Removed'], $report->rows());

        $this->summarise($importer, $report, $dryRun);

        return self::SUCCESS;
    }

    private function summarise(HouseImporter $importer, HouseImportReport $report, bool $dryRun): void
    {
        $counts = $dryRun ? [] : $importer->chunkCounts();

        $this->line(sprintf(
            '%d new, %d changed, %d unchanged, %d removed, in %.2fs.',
            $report->total('created'),
            $report->total('updated'),
            $report->total('unchanged'),
            $report->total('deleted'),
            $report->seconds,
        ));

        if ($counts !== []) {
            $this->line(sprintf(
                '%d chunk(s) at renderer v%d: %s.',
                array_sum($counts),
                HouseRenderer::VERSION,
                implode(', ', array_map(
                    fn (string $kind, int $count): string => "{$count} {$kind}",
                    array_keys($counts),
                    $counts,
                )),
            ));
        }

        $this->newLine();

        if ($dryRun) {
            $this->line('<fg=gray>Dry run; the work was done inside a transaction and rolled back, so these are the real counts.</>');

            return;
        }

        if (! $report->wroteAnything()) {
            $this->line('<fg=gray>Nothing moved. A second run writing nothing is the idempotency check.</>');

            return;
        }

        $this->line('<fg=gray>Run `house:import --verify` to check the catalog against its invariants, then `house:embed`.</>');
    }

    /**
     * Report every way the imported catalog could be wrong.
     */
    private function verify(HouseImporter $importer): int
    {
        $failures = $importer->verify();

        $this->newLine();

        if ($failures === []) {
            $this->info('Verified: every invariant holds.');

            return self::SUCCESS;
        }

        foreach ($failures as $failure) {
            $this->line("  <fg=red>✗</> {$failure}");
        }

        $this->newLine();
        $this->error(count($failures).' invariant(s) broken.');

        return self::FAILURE;
    }
}
