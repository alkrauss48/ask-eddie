<?php

namespace App\Services\House;

/**
 * What one import run did, per table.
 *
 * Kept as its own object for the same reason ChunkingReport is: the numbers are
 * what the run is judged on -- a second run with no content change must show
 * nothing but "unchanged", and that is an assertion rather than a statistic.
 */
class HouseImportReport
{
    /** @var array<string, array{created: int, updated: int, unchanged: int, deleted: int}> */
    public array $entities = [];

    public float $seconds = 0.0;

    public function record(string $entity, string $state, int $count = 1): void
    {
        $this->entities[$entity] ??= ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0];
        $this->entities[$entity][$state] += $count;
    }

    public function total(string $state): int
    {
        return array_sum(array_column($this->entities, $state));
    }

    /**
     * Whether this run wrote anything at all.
     *
     * The idempotency check in one call: run the import twice and the second
     * one must answer false.
     */
    public function wroteAnything(): bool
    {
        return $this->total('created') > 0
            || $this->total('updated') > 0
            || $this->total('deleted') > 0;
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->entities as $entity => $counts) {
            $rows[] = [
                $entity,
                $counts['created'] > 0 ? "<fg=green>{$counts['created']}</>" : '0',
                $counts['updated'] > 0 ? "<fg=yellow>{$counts['updated']}</>" : '0',
                (string) $counts['unchanged'],
                $counts['deleted'] > 0 ? "<fg=red>{$counts['deleted']}</>" : '0',
            ];
        }

        return $rows;
    }
}
