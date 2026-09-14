<?php

namespace App\Services\Retrieval;

/**
 * Merges ranked id lists into one order.
 *
 * Reciprocal Rank Fusion scores a document as the sum over the channels that
 * found it of w / (k + rank). It uses only rank, never the channels' own
 * scores, which is the whole point: cosine similarity and ts_rank_cd are not
 * comparable numbers, and any attempt to normalize them into one scale is a
 * guess that changes with the corpus.
 *
 * The k term damps the top of each list. At k = 60 rank 1 contributes 1/61 and
 * rank 10 contributes 1/70, so a passage both channels found in their top ten
 * outranks one that a single channel put first. That is the property being
 * bought: agreement between an embedding and a keyword match is better evidence
 * than either alone.
 *
 * Pure, and deliberately so -- two lists of at most sixty integers, no database,
 * no models, unit tested on its own.
 */
class ReciprocalRankFusion
{
    /**
     * @param  array<string, list<int>>  $channels  ranked chunk ids, best first, keyed by channel
     * @param  array<string, float>  $weights  per-channel multiplier, defaulting to 1.0
     * @return list<FusedChunk> best first
     */
    public function fuse(array $channels, array $weights = [], int $k = 60): array
    {
        /** @var array<int, array{score: float, ranks: array<string, int>}> $fused */
        $fused = [];

        foreach ($channels as $channel => $ids) {
            $weight = $weights[$channel] ?? 1.0;

            foreach (array_values($ids) as $position => $id) {
                $rank = $position + 1;

                $fused[$id] ??= ['score' => 0.0, 'ranks' => []];
                $fused[$id]['score'] += $weight / ($k + $rank);
                $fused[$id]['ranks'][$channel] = $rank;
            }
        }

        $results = [];

        foreach ($fused as $id => $entry) {
            $results[] = new FusedChunk($id, $entry['score'], $entry['ranks']);
        }

        // Ties broken by id so the order is deterministic. Two chunks found at
        // the same rank by the same channels are genuinely indistinguishable
        // here, and an unstable sort would make a retrieval test flap.
        usort($results, fn (FusedChunk $a, FusedChunk $b): int => $b->score <=> $a->score ?: $a->id <=> $b->id);

        return $results;
    }
}
