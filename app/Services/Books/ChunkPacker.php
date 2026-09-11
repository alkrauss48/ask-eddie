<?php

namespace App\Services\Books;

use App\Enums\ChunkStrategy;
use RuntimeException;

/**
 * Packs blocks into chunks, and never cuts a recipe in half.
 *
 * The two limits here are enforced rather than hoped for. `max_chars` is
 * asserted before a chunk is emitted, because the Python original treated its
 * equivalent as a packing threshold: a single long "sentence" sailed past it and
 * the embedding model then truncated the tail, silently, with the chunk still
 * indexed on the strength of text nobody had chosen. And every chunk must start
 * later than the one before it, or the loop does not terminate.
 *
 * Overlap is decided per chunk rather than per book. A chunk of recipes gets
 * none: a tail borrowed from the previous chunk would put a neighbouring drink's
 * ingredients under this chunk's citation, which is the precise failure this
 * whole phase exists to prevent. Prose gets overlap, taken as whole sentences
 * from the immediately preceding text -- which, because overlap is a slice of
 * the stream at the chunk's own start offset, cannot splice in text from
 * somewhere else in the book the way the Python's could.
 */
class ChunkPacker
{
    public function __construct(private readonly TokenEstimator $tokens) {}

    /**
     * @param  list<TextBlock>  $blocks
     * @return list<PendingChunk>
     */
    public function pack(BookTextStream $stream, array $blocks, ChunkStrategy $strategy, DetectedStructure $structure): array
    {
        $target = (int) config('books.chunking.target_chars');
        // Content is packed to a ceiling that reserves room for the overlap
        // stored alongside it; assertWithinCeiling() still holds the real one.
        $ceiling = $this->tokens->contentCharacterCeiling();
        $tokenCeiling = $this->tokens->contentTokenCeiling();
        $minimum = (int) config('books.chunking.min_chars');

        $chunks = [];
        $current = [];

        $flush = function () use (&$chunks, &$current, $stream, $strategy): void {
            if ($current === []) {
                return;
            }

            $chunks[] = $this->build($stream, $current, $strategy, $chunks);
            $current = [];
        };

        foreach ($blocks as $block) {
            if ($current !== []) {
                $span = $this->spanOf($current, $block);
                $text = $stream->slice($span[0], $span[1]);

                $tooLong = strlen($text) > $ceiling || $this->tokens->estimate($text) > $tokenCeiling;
                $bigEnough = $this->spanLength($current) >= $minimum;
                $pastTarget = $this->spanLength($current) >= $target;

                // A section change always ends a chunk: a chunk spanning two
                // sections would carry a title true of only half of it.
                $leftSection = $this->sectionKey($stream, $block, $structure)
                    !== $this->sectionKey($stream, $current[0], $structure);

                // A heading ends a chunk once there is enough in it to stand
                // alone, so several short recipes travel together while a long
                // one starts fresh. Under packing, any block boundary will do
                // once the target is reached.
                $atHeading = $block->heading !== null && $bigEnough;
                $atBlock = $pastTarget && $strategy === ChunkStrategy::Packing;

                if ($tooLong || $leftSection || $atHeading || $atBlock) {
                    $flush();
                }
            }

            $current[] = $block;
        }

        $flush();

        return $this->mergeUndersized($stream, $chunks, $strategy, $minimum);
    }

    /**
     * Whether a block may open a new chunk.
     *
     * Under the heading strategy only a heading may, which is what keeps a
     * recipe whole. Under packing any block boundary will do.
     */
    private function isBoundary(TextBlock $block, ChunkStrategy $strategy): bool
    {
        return $strategy === ChunkStrategy::Headings
            ? $block->heading !== null
            : true;
    }

    /**
     * An identifier for the section a block falls in, or null outside them all.
     *
     * Resolved through the page the block sits on, since sections are detected
     * from running heads and are therefore page-shaped, while blocks are
     * offset-shaped.
     */
    private function sectionKey(BookTextStream $stream, TextBlock $block, DetectedStructure $structure): ?string
    {
        $pages = $stream->pagesCovering($block->start, $block->end);

        if ($pages === []) {
            return null;
        }

        $section = $structure->sectionFor($pages[0]->pageNumber);

        return $section === null ? null : $section->pageFrom.':'.($section->title ?? '');
    }

    /**
     * @param  list<TextBlock>  $current
     * @return array{0: int, 1: int}
     */
    private function spanOf(array $current, ?TextBlock $block = null): array
    {
        $start = $current[0]->start;
        $end = $block?->end ?? $current[count($current) - 1]->end;

        return [$start, $end];
    }

    /**
     * @param  list<TextBlock>  $current
     */
    private function spanLength(array $current): int
    {
        [$start, $end] = $this->spanOf($current);

        return $end - $start;
    }

    /**
     * @param  list<TextBlock>  $blocks
     * @param  list<PendingChunk>  $emitted
     */
    private function build(BookTextStream $stream, array $blocks, ChunkStrategy $strategy, array $emitted): PendingChunk
    {
        [$start, $end] = $this->spanOf($blocks);

        $headings = [];
        $recipeHeadings = 0;
        $lineCount = 0;
        $hardCut = false;

        foreach ($blocks as $block) {
            if ($block->heading !== null) {
                $headings[] = $block->heading->text;

                if ($block->opensRecipe()) {
                    $recipeHeadings++;
                }
            }

            $lineCount += $block->lineCount;
            $hardCut = $hardCut || $block->hardCut;
        }

        // Overlap is borrowed on top of the chunk's own span, so it has to fit
        // inside the same ceiling. Without this clamp a full-size chunk plus its
        // overlap sailed straight past it.
        $headroom = max(0, $this->tokens->characterCeiling() - ($end - $start));
        $overlap = min($this->overlapFor($stream, $start, $recipeHeadings, $emitted), $headroom);

        // Overlap costs tokens as well as characters, and a chunk already at the
        // token ceiling has none to spare. Dropped rather than trimmed, because
        // a partial overlap snapped to some arbitrary byte is worth less than
        // none and risks cutting a character.
        if ($overlap > 0 && $this->tokens->estimate($stream->slice($start - $overlap, $end)) > $this->tokens->tokenCeiling()) {
            $overlap = 0;
        }

        $chunk = new PendingChunk(
            $stream->slice($start - $overlap, $end),
            $start - $overlap,
            $end,
            $overlap,
            $headings[0] ?? null,
            array_values(array_unique($headings)),
            $recipeHeadings,
            max(1, $lineCount),
            array_filter([
                'strategy' => $strategy->value,
                'blocks' => count($blocks),
                'hard_cut' => $hardCut ?: null,
            ], fn ($value): bool => $value !== null),
        );

        $this->assertWithinCeiling($chunk);

        return $chunk;
    }

    /**
     * How much of the preceding text this chunk should carry.
     *
     * @param  list<PendingChunk>  $emitted
     */
    private function overlapFor(BookTextStream $stream, int $start, int $recipeHeadings, array $emitted): int
    {
        $budget = (int) config('books.chunking.overlap_chars');

        if ($budget <= 0 || $recipeHeadings > 0 || $emitted === []) {
            return 0;
        }

        $previous = $emitted[count($emitted) - 1];

        // Never reach back past the previous chunk's own start, or two chunks
        // could report the same text as their content.
        $earliest = max($previous->contentStart() + 1, $start - $budget);

        if ($earliest >= $start) {
            return 0;
        }

        $window = $stream->slice($earliest, $start);

        // Snap forward to a sentence boundary so the overlap reads as text
        // rather than starting mid-clause -- and, just as importantly, so it
        // starts on a character boundary. The budget is counted in bytes, so
        // "$start - $budget" can land inside a multibyte character; this corpus
        // is full of "Curaçao" and "café", and a slice taken mid-character is
        // invalid UTF-8 that Postgres rejects outright.
        if (preg_match('/[.!?]["\'\)\]]?\s+/u', $window, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $earliest += $matches[0][1] + strlen($matches[0][0]);
        } elseif (preg_match('/\s+/u', $window, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $earliest += $matches[0][1] + strlen($matches[0][0]);
        } else {
            return 0;
        }

        return max(0, $start - $earliest);
    }

    /**
     * Fold a chunk too small to stand alone into its neighbour.
     *
     * If it has no neighbour to join, it is kept as it is and flagged. The
     * Python discarded anything under twenty characters, which in this corpus
     * means discarding "Gin." and "1/2".
     *
     * @param  list<PendingChunk>  $chunks
     * @return list<PendingChunk>
     */
    private function mergeUndersized(BookTextStream $stream, array $chunks, ChunkStrategy $strategy, int $minimum): array
    {
        $merged = [];

        foreach ($chunks as $chunk) {
            $previous = $merged === [] ? null : $merged[count($merged) - 1];

            if ($previous === null || strlen($chunk->text) >= $minimum) {
                $merged[] = $chunk;

                continue;
            }

            $combined = $stream->slice($previous->start, $chunk->end);

            if (strlen($combined) > $this->tokens->contentCharacterCeiling()
                || $this->tokens->estimate($combined) > $this->tokens->contentTokenCeiling()) {
                $chunk->signals['undersized'] = true;
                $merged[] = $chunk;

                continue;
            }

            $previous->text = $combined;
            $previous->end = $chunk->end;
            $previous->headings = array_values(array_unique(array_merge($previous->headings, $chunk->headings)));
            $previous->heading ??= $chunk->heading;
            $previous->recipeHeadings += $chunk->recipeHeadings;
            $previous->lineCount += $chunk->lineCount;
        }

        return $merged;
    }

    /**
     * The ceiling is a promise to the embedding model, so it is checked.
     *
     * Both invariants are asserted rather than tested for, because both failures
     * are silent everywhere else: an oversized chunk gets truncated by the
     * embedding model with nothing to show for it, and a chunk cut inside a
     * character is invalid UTF-8 that fails at the far end of a long run.
     */
    private function assertWithinCeiling(PendingChunk $chunk): void
    {
        $ceiling = $this->tokens->characterCeiling();

        if (strlen($chunk->text) > $ceiling) {
            throw new RuntimeException(sprintf(
                'Chunk at offset %d is %d bytes, over the %d byte ceiling.',
                $chunk->start,
                strlen($chunk->text),
                $ceiling,
            ));
        }

        $tokens = $this->tokens->estimate($chunk->text);

        if ($tokens > $this->tokens->tokenCeiling()) {
            throw new RuntimeException(sprintf(
                'Chunk at offset %d estimates %d tokens, over the %d token ceiling.',
                $chunk->start,
                $tokens,
                $this->tokens->tokenCeiling(),
            ));
        }

        if (! mb_check_encoding($chunk->text, 'UTF-8')) {
            throw new RuntimeException(sprintf(
                'Chunk at offset %d was cut inside a character.',
                $chunk->start,
            ));
        }
    }
}
