<?php

namespace App\Console\Commands\House;

use Illuminate\Console\Command;

/**
 * Fetches, imports, verifies and embeds the house export in one go.
 *
 * Only a sequence of the existing commands, stopping at the first failure:
 * importing an export that failed to download, or embedding a catalog that
 * failed its invariants, would put a corpus in front of the bartenders that
 * nothing checked. Every step is already idempotent, so a refresh against an
 * unchanged site downloads nothing, writes nothing and embeds nothing.
 */
class RefreshCommand extends Command
{
    protected $signature = 'house:refresh
        {--from= : Base URL to download from, instead of config(\'house.fetch_url\')}';

    protected $description = 'Fetch the house export from the live site, import it, verify it and embed it';

    public function handle(): int
    {
        /** @var list<array{string, array<string, mixed>, string}> $steps */
        $steps = [
            ['house:fetch', array_filter(['--from' => $this->option('from')]), 'house:fetch'],
            ['house:import', [], 'house:import'],
            ['house:import', ['--verify' => true], 'house:import --verify'],
            ['house:embed', [], 'house:embed'],
        ];

        foreach ($steps as [$command, $arguments, $label]) {
            $this->newLine();
            $this->line("<fg=yellow>→ {$label}</>");

            if ($this->call($command, $arguments) !== self::SUCCESS) {
                $this->newLine();
                $this->error("house:refresh stopped at `{$label}`; nothing after it ran.");

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('The house catalog is current and embedded.');

        return self::SUCCESS;
    }
}
