<?php

namespace App\Services\Books;

use App\Enums\ChunkKind;

/**
 * Decides what a chunk is, and never decides to delete it.
 *
 * This is where `.ai/rules/books.md` sends the garbage problem: "Garbage
 * filtering belongs at chunk time where there is context." The rule it must not
 * break is the one that sent it here -- nothing in this pipeline silently loses
 * a line -- so this class labels rather than discards. Excluding an index page
 * from retrieval is then a query, and re-including it is an update.
 *
 * Two rules are worth stating outright, because the Python original got both
 * backwards:
 *
 * 1. Short lines are evidence *for* a recipe, not against one. The Python
 *    dropped any chunk whose lines were 80% short as "a list", which would
 *    delete every page of Cafe Royal, whose median block is 20 characters. Here
 *    a recipe is recognised positively, from a heading plus a measure, and that
 *    test runs before any density heuristic.
 *
 * 2. Noise is defined narrowly. "3%" and ".~ °°" are noise. "~ 1 small lump of
 *    ice." is a real ingredient line that OCR mangled, and it must survive --
 *    the Python's twenty-character minimum ate text like this.
 */
class ChunkClassifier
{
    /**
     * Bumped independently of BookChunker::VERSION, because reclassifying is a
     * pass over stored chunk text: no stream, no re-segmentation, no new
     * boundaries. The same relationship books:renormalize has to books:extract.
     */
    public const VERSION = 1;

    private const MEASURE_TOKENS = '/(\d+\/\d+|[½¼¾⅓⅔⅛]|\b(dash(es)?|jigger|pony|gill|spoonful|teaspoonful|tablespoonful|wine-?glass|liqueur-?glass|pieces?|lumps?|drops?|slices?|parts?|oz|ounces?|dessert-?spoonful)\b)/iu';

    private const INSTRUCTION_VERBS = '/\b(shake|strain|stir|fill|pour|frappe|frappé|serve|mix|float|dress|garnish|sweeten|grate|squeeze)\b/iu';

    private const ADVERTISEMENT_WORDS = '/\b(post free|postage|crown 8vo|8vo|12mo|i2mo|uniform with|all booksellers|catalogue|new edition|sent on receipt|paper cover|gold cloth|cents per copy|for sale by|published by|printed by)\b/iu';

    private const FRONT_MATTER_WORDS = '/\b(copyright|all rights reserved|entered according to act of congress|library of congress|preface|dedication|contents|printed in)\b/iu';

    public function __construct(private readonly PageTextQuality $quality) {}

    public function classify(string $text, ClassificationContext $context): ChunkClassification
    {
        $trimmed = trim($text);
        $score = $this->quality->score($trimmed);

        $signals = [
            'chars' => mb_strlen($trimmed),
            'lines' => $context->lineCount,
            'letter_ratio' => $this->letterRatio($trimmed),
            'dot_leader_ratio' => $this->dotLeaderRatio($trimmed),
            'trailing_number_ratio' => $this->trailingNumberRatio($trimmed),
            'inverted_entry_ratio' => $this->invertedEntryRatio($trimmed),
            'quality_score' => $score->score,
        ];

        // Noise first, and deliberately hard to qualify for.
        if ($this->isNoise($trimmed, $signals['letter_ratio'])) {
            return new ChunkClassification(ChunkKind::Noise, $signals + ['reason' => 'artefact'], $score->score);
        }

        // Index entries are tested first, but only on signals a recipe cannot
        // produce: dot leaders, and lines ending in a bare page number. An
        // index page of a book of drinks is full of drink names, and one of them
        // carried an OCR-mangled heading and a stray verb, which was enough to
        // read a whole index as a recipe when this test came second.
        if ($this->isIndexLike($signals)) {
            $kind = $context->isBeforeBody() ? ChunkKind::TableOfContents : ChunkKind::Index;

            return new ChunkClassification($kind, $signals + ['reason' => 'index_entries'], $score->score);
        }

        // A recipe is recognised positively, and before any density test, so
        // that a page of twenty-character ingredient lines is never mistaken for
        // a list. This is the rule the Python original had backwards.
        if ($context->headingPresent && $this->looksLikeRecipe($trimmed)) {
            return new ChunkClassification(
                $context->recipeHeadings >= 3 ? ChunkKind::RecipeList : ChunkKind::Recipe,
                $signals + ['reason' => 'heading_and_measures', 'recipe_headings' => $context->recipeHeadings],
                $score->score,
            );
        }

        if ($this->isAdvertisement($trimmed, $context)) {
            return new ChunkClassification(
                ChunkKind::Advertisement,
                $signals + ['reason' => 'keyword_and_structure'],
                $score->score,
            );
        }

        if ($context->isBeforeBody() && $this->looksLikeFrontMatter($trimmed, $context)) {
            return new ChunkClassification(ChunkKind::FrontMatter, $signals + ['reason' => 'before_body'], $score->score);
        }

        if ($context->isAfterBody()) {
            return new ChunkClassification(ChunkKind::BackMatter, $signals + ['reason' => 'after_body'], $score->score);
        }

        // A low quality score is a severity flag, not a verdict, so it only
        // downgrades text that nothing else could account for. An unscorable
        // chunk -- fewer than eight tokens -- stays indexable: a three-line
        // recipe is not garbage.
        if ($score->score !== null && $score->score < (float) config('books.chunking.noise_score')) {
            return new ChunkClassification($signals['chars'] < 40 ? ChunkKind::Noise : ChunkKind::Unknown,
                $signals + ['reason' => 'low_quality'], $score->score);
        }

        if ($this->hasSentenceStructure($trimmed)) {
            return new ChunkClassification(ChunkKind::Prose, $signals + ['reason' => 'sentences'], $score->score);
        }

        // Nothing matched. Fail open.
        return new ChunkClassification(ChunkKind::Unknown, $signals + ['reason' => 'unmatched'], $score->score);
    }

    /**
     * Scanner marks and stray glyphs: "3%", ".~ °°".
     *
     * Both conditions are required, and both are strict, because this is the
     * only kind that removes real-looking text from retrieval on its own.
     */
    private function isNoise(string $text, float $letterRatio): bool
    {
        return mb_strlen($text) < 12 && $letterRatio < 0.10;
    }

    private function looksLikeRecipe(string $text): bool
    {
        return preg_match(self::MEASURE_TOKENS, $text) === 1
            || preg_match(self::INSTRUCTION_VERBS, $text) === 1;
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function isIndexLike(array $signals): bool
    {
        if ((float) $signals['dot_leader_ratio'] >= 0.4) {
            return true;
        }

        // Nearly every line ending in a bare number is an index on its own.
        // Recipe lines end in a measure or a full stop, not a page reference.
        if ((float) $signals['trailing_number_ratio'] >= 0.7) {
            return true;
        }

        return (float) $signals['trailing_number_ratio'] >= 0.5
            && (float) $signals['inverted_entry_ratio'] >= 0.3;
    }

    /**
     * A publisher's advertisement, which needs two independent signals.
     *
     * One is not enough: a punch recipe can mention a price, and the known
     * contamination is two pages out of 5,099. A keyword list on its own would
     * buy those two pages and cost false positives across the other 26 books.
     */
    private function isAdvertisement(string $text, ClassificationContext $context): bool
    {
        if (! $context->isEnglish() || preg_match(self::ADVERTISEMENT_WORDS, $text) !== 1) {
            return false;
        }

        $lines = array_values(array_filter(
            explode("\n", $text),
            fn (string $line): bool => trim($line) !== '',
        ));

        if (count($lines) < 3) {
            return false;
        }

        $priced = 0;

        foreach ($lines as $line) {
            if (preg_match('/(\$|£)\s*\d|\b\d+\s*(cents?|shillings?|d\.|s\.)\s*$|\b(8vo|12mo|i2mo)\b/iu', $line) === 1) {
                $priced++;
            }
        }

        return $priced >= 2;
    }

    private function looksLikeFrontMatter(string $text, ClassificationContext $context): bool
    {
        return $context->isEnglish() && preg_match(self::FRONT_MATTER_WORDS, $text) === 1;
    }

    private function hasSentenceStructure(string $text): bool
    {
        return (preg_match_all('/[.!?][\s\n]/u', $text) ?: 0) >= 2;
    }

    private function letterRatio(string $text): float
    {
        $length = mb_strlen($text);

        if ($length === 0) {
            return 0.0;
        }

        return mb_strlen(preg_replace('/[^\p{L}]/u', '', $text) ?? '') / $length;
    }

    /**
     * The share of lines that trail off into dots, as an index entry does.
     */
    private function dotLeaderRatio(string $text): float
    {
        return $this->lineShare($text, '/\.{3,}|(?:\.\s){3,}/u');
    }

    private function trailingNumberRatio(string $text): float
    {
        return $this->lineShare($text, '/\d+\s*$/u');
    }

    /**
     * The share of lines written surname-first, as in "Sangaree, Ale".
     */
    private function invertedEntryRatio(string $text): float
    {
        return $this->lineShare($text, '/^[^,\n]{1,30},\s/u');
    }

    private function lineShare(string $text, string $pattern): float
    {
        $lines = array_values(array_filter(
            explode("\n", $text),
            fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === []) {
            return 0.0;
        }

        $matching = 0;

        foreach ($lines as $line) {
            if (preg_match($pattern, $line) === 1) {
                $matching++;
            }
        }

        return $matching / count($lines);
    }
}
