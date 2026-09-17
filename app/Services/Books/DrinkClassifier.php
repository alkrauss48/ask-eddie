<?php

namespace App\Services\Books;

/**
 * Decides whether a drink row is a drink, and never decides to delete it.
 *
 * The first real run produced 9,437 rows, of which 7,011 were printed in
 * exactly one book. That tail is mostly OCR wreckage ("Thiet Dtn", "Caucliois")
 * and prose that a heading pattern caught ("Israel Hatch announced daily stages
 * between"). Every one of them is a real string at a real offset, so --verify
 * has no objection to any of it; what it ruins is the tally. Ordering by
 * first_year returned "T He", "This", "There" and "Page" out of the 1757 book
 * before it returned a drink, and 9,437 was not a number Eddie could say.
 *
 * This labels rather than discards, which is the rule .ai/rules/books.md sets
 * for chunks and which applies here for a stronger reason: a mention is
 * evidence that a book printed a string at an offset, and that stays true
 * whatever this class decides. Excluding it from a tally is a query.
 *
 * Two findings from the corpus shaped the rules, and both cut against the
 * obvious design:
 *
 * 1. Recipe-chunk share does NOT separate drinks from noise, so it is recorded
 *    and not acted on. Below a quarter of mentions in recipe chunks sit "Gothic
 *    Punch", "Bilberry Cordial", "Hock Cobbler" and "Soldiers Camping Punch" --
 *    real drinks that this corpus happens to print only inside prose. A
 *    threshold anywhere in that band costs more drinks than it buys.
 *
 * 2. The surviving function words are not structurally distinguishable. "This"
 *    (6 books) and "Bishop" (27 books) differ only in that one is an English
 *    function word. That is a list, not a heuristic, so it is config -- beside
 *    stop_headings, which is the same kind of list for the same kind of reason.
 *    `books:drinks --noise` proposes candidates; a human pastes them in.
 */
class DrinkClassifier
{
    /**
     * Bumped independently of DrinkExtractor::VERSION, because reclassifying is
     * a pass over stored mentions: no re-scan, no new offsets. The same
     * relationship ChunkClassifier::VERSION has to BookChunker::VERSION.
     */
    public const VERSION = 1;

    /**
     * An ingredient line that a title-case heading pattern caught.
     *
     * "White of one egg", "Juice of one Lime", "Yolk of an egg" -- printed on
     * their own line inside a recipe, indistinguishable from a heading by shape
     * alone. Anchored to the start so "Brandy Egg Nogg" and "Egg Phosphate",
     * which are drinks, are untouched.
     */
    private const INGREDIENT_LINE = '/^(the\s+)?(white|yolk|juice|peel|rind|zest|slice|piece|lump|dash|spoonful|teaspoonful|tablespoonful|wineglass(ful)?)\s+of\b|^(one|two|three|four|half|a|an|i)\s+(egg|lime|lemon|orange|lump|dash|spoonful|glass|pony|jigger)(e?s)?\b/iu';

    public function __construct(private readonly DrinkNameNormalizer $normalizer) {}

    public function classify(string $canonicalName, string $canonicalKey, DrinkEvidence $evidence): DrinkClassification
    {
        $signals = $evidence->signals() + [
            'words' => $this->wordCount($canonicalName),
            'letter_ratio' => $this->letterRatio($canonicalName),
        ];

        // A division heading is a section of a book, not a drink in it.
        // verify() reports these separately as a failure rather than treating
        // this exclusion as the fix -- a stop heading reaching the table at all
        // means the scanner let one through.
        if ($this->normalizer->isStopHeading($canonicalKey)) {
            return DrinkClassification::uncountable('division_heading', $signals);
        }

        if (in_array($canonicalKey, $this->noiseHeadings(), true)) {
            return DrinkClassification::uncountable('noise_heading', $signals);
        }

        // The dominant rule, and the one the tail needs. A drink two books
        // printed independently is a drink; a string one book printed once is,
        // far more often than not, this corpus's OCR.
        if ($evidence->bookCount < (int) config('books.drinks.classification.min_books')) {
            return DrinkClassification::uncountable('single_book', $signals);
        }

        // Positive recognition before any shape test, the discipline
        // ChunkClassifier uses: a book that numbered its own recipes is the best
        // evidence available that the thing numbered was one, so a name carrying
        // a numbered heading is countable whatever it looks like.
        if ($evidence->numberedHeading) {
            return DrinkClassification::countable('numbered_recipe', $signals);
        }

        if ($signals['words'] > (int) config('books.drinks.classification.max_words')) {
            return DrinkClassification::uncountable('sentence_fragment', $signals);
        }

        if (preg_match(self::INGREDIENT_LINE, trim($canonicalName)) === 1) {
            return DrinkClassification::uncountable('ingredient_line', $signals);
        }

        // "13u", "157 Whiskey Dip" -- a name carrying more digits and stray
        // punctuation than letters is a page number or a scanner mark that a
        // heading pattern read as a title.
        if ($signals['letter_ratio'] < (float) config('books.drinks.classification.min_letter_ratio')) {
            return DrinkClassification::uncountable('ocr_artefact', $signals);
        }

        return DrinkClassification::countable('printed_in_books', $signals);
    }

    /**
     * Single-word names seen only as a paragraph's opening capital.
     *
     * Not a verdict -- `books:drinks --noise` prints these for review, and
     * anything genuinely noise gets pasted into books.drinks.noise_headings.
     * The reason it is a suggestion and not a rule is that the same shape
     * catches "Kummel", "Cooler" and "Tequila", which are drinks. This is the
     * doctrine config/books.php already states for fuzzy name merges: an
     * offline proposal for a human, never a write.
     */
    public function looksLikeSentenceOpener(string $canonicalName, DrinkEvidence $evidence): bool
    {
        return $this->wordCount($canonicalName) === 1
            && $evidence->capsPrefixShare >= 1.0
            && ! $evidence->numberedHeading;
    }

    /**
     * @return list<string>
     */
    private function noiseHeadings(): array
    {
        /** @var list<string> $headings */
        $headings = config('books.drinks.classification.noise_headings', []);

        return $headings;
    }

    private function wordCount(string $name): int
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? 0 : count($words);
    }

    private function letterRatio(string $name): float
    {
        $stripped = preg_replace('/\s+/u', '', $name) ?? '';
        $length = mb_strlen($stripped);

        if ($length === 0) {
            return 0.0;
        }

        return round(mb_strlen(preg_replace('/[^\p{L}]/u', '', $stripped) ?? '') / $length, 3);
    }
}
