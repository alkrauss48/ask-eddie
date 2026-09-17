<?php

namespace App\Services\Books;

use App\Models\BookChunk;

/**
 * Finds the drink names printed inside a chunk.
 *
 * It re-scans the chunk's stored text rather than reading its `headings`
 * column, and the difference is recall rather than tidiness:
 * BlockSegmenter::headingOf() only tests the first line of each paragraph
 * block, so a heading printed on the fifth line of a block never reached that
 * column -- in books cut either way. Re-scanning is a pass over text already in
 * the database, no stream and no PDF, which is the same relationship
 * ChunkClassifier has to BookChunker.
 *
 * It feeds on every indexable chunk, not only the recipe kinds. That keeps this
 * layer and the citation layer drawing from one universe, and it is where the
 * missing names are: a recipe whose title sits mid-block has no block heading,
 * so ChunkClassifier never saw `headingPresent` and filed the whole thing as
 * prose.
 *
 * What that same predicate excludes matters more than anything else here. Index
 * and contents chunks are `is_indexable = false`, so they never arrive. A
 * book's own index lists every drink in it exactly once, with a page number
 * pointing somewhere the chunk does not cover; counting it would roughly double
 * every recipe book's tally and attach un-citable pages to it.
 */
class DrinkHeadingScanner
{
    /**
     * Bumped when detection or acceptance changes, which means a re-scan.
     * Folding moves under DrinkNameNormalizer::VERSION instead.
     */
    public const VERSION = 1;

    public function __construct(private readonly HeadingPatterns $patterns) {}

    /**
     * @return list<PendingMention>
     */
    public function scan(BookChunk $chunk): array
    {
        // Byte offsets throughout, so substr() rather than mb_substr(). A prose
        // chunk opens with whole sentences borrowed from the chunk before it,
        // and a heading inside that tail belongs to the previous chunk's
        // citation -- counting it here would file a drink on the wrong page.
        $body = substr($chunk->text, $chunk->overlap_chars);
        $lines = explode("\n", $body);

        $mentions = [];
        $offset = $chunk->overlap_chars;

        foreach ($lines as $index => $line) {
            $match = $this->patterns->match($line, $this->nextPopulated($lines, $index));

            if ($match !== null && $this->accepts($match, $lines, $index)) {
                // Anchored to the heading text rather than to the line that
                // carries it. "128. Gin Sangaree." stores "Gin Sangaree", which
                // begins five bytes in, and "BLUE LADY 1/2 Blue Curasao" stores
                // a prefix -- so only this offset makes the stored string
                // byte-identical to the corpus at the offset it claims, which
                // is what --verify asserts and what proves the page range.
                $within = strpos($line, $match->text);

                if ($within !== false) {
                    $mentions[] = new PendingMention(
                        $match->text,
                        $match->family,
                        $match->number,
                        $offset + $within,
                    );
                }
            }

            // +1 for the newline explode() consumed.
            $offset += strlen($line) + 1;
        }

        return $mentions;
    }

    /**
     * Whether a matched heading names a drink rather than a division.
     *
     * Deliberately not HeadingPatterns::scan(), which collapses a run of
     * consecutive heading lines into the first of the run. That is right for
     * measuring how densely a book is structured and wrong here: "PUNCHES."
     * printed directly above "Gin Punch." would swallow the drink. This is the
     * third caller of that class and the only one that wants recall over
     * stored text rather than placement of a cut.
     *
     * @param  list<string>  $lines
     */
    private function accepts(HeadingMatch $match, array $lines, int $index): bool
    {
        return match ($match->family) {
            // "131. TODDIES AND SLINGS" is already flagged by the pattern.
            HeadingPatterns::NUMBERED => ! $match->sectionLike,

            // Both already required a recipe to follow: caps_prefix matches only
            // when an ingredient tail shares the line, and title_line only when
            // the next line opens with a measure.
            HeadingPatterns::CAPS_PREFIX, HeadingPatterns::TITLE_LINE => true,

            // matchCapsLine() flags every caps line sectionLike, so that flag
            // cannot decide this one: in a book that shouts its drink names,
            // every drink carries it. What follows the line decides instead --
            // "GIN SLING." above a measure is a drink, "PUNCHES." above another
            // heading is not.
            HeadingPatterns::CAPS_LINE => $this->opensARecipeBelow($lines, $index),

            default => false,
        };
    }

    /**
     * @param  list<string>  $lines
     */
    private function opensARecipeBelow(array $lines, int $index): bool
    {
        $next = $this->nextPopulated($lines, $index);

        return $next !== null && $this->patterns->opensARecipe($next);
    }

    /**
     * @param  list<string>  $lines
     */
    private function nextPopulated(array $lines, int $index): ?string
    {
        $count = count($lines);

        for ($next = $index + 1; $next < $count; $next++) {
            if (trim($lines[$next]) !== '') {
                return $lines[$next];
            }
        }

        return null;
    }
}
