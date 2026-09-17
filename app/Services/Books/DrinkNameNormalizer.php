<?php

namespace App\Services\Books;

use Illuminate\Support\Str;

/**
 * Folds a printed heading into the key two spellings of one drink share.
 *
 * Every step here is deterministic and reviewable, which is the whole design.
 * The alternative -- clustering names by embedding -- fails in exactly the
 * direction that hurts: bge-m3 places "Blue Lady" nearer "Pink Lady", and "Gin
 * Fizz" nearer "Gin Rickey", than either sits to its own OCR misreading. A
 * vector also cannot be re-derived after a model change, which would break the
 * version-constant contract the rest of this pipeline rests on, and "0.94
 * similar" is not a reason a person can check.
 */
class DrinkNameNormalizer
{
    /**
     * Bumped when the fold changes, independently of DrinkExtractor::VERSION,
     * because re-folding names is a pass over stored mentions: no re-scan of
     * chunk text, no new offsets. The same relationship ChunkClassifier has to
     * BookChunker.
     */
    public const VERSION = 1;

    public function __construct(private readonly HeadingPatterns $patterns) {}

    /**
     * The folded key two spellings of one drink must agree on.
     *
     * trimEdges() comes first and is not optional: matchNumbered() returns its
     * heading with the trailing period still attached, while the caps families
     * strip theirs, so "128. Gin Sangaree." and "GIN SANGAREE" would otherwise
     * fold two ways and count as two drinks.
     */
    public function key(string $rawHeading): string
    {
        $text = $this->patterns->trimEdges($rawHeading);

        // Str::ascii() rather than iconv('UTF-8', 'ASCII//TRANSLIT'), which
        // depends on the current locale and answers differently in the
        // container than on a host, or Normalizer, which needs ext-intl and is
        // not a declared requirement. Str::ascii() carries a static
        // transliteration table: the same answer everywhere, forever.
        $text = Str::ascii($text);
        $text = mb_strtolower($text);

        $text = preg_replace('/^(the|a|an|le|la|el|les|los|las)\s+/u', '', $text) ?? $text;

        // "Bishop's Cocktail" and "Bishops Cocktail" are one drink.
        $text = preg_replace('/[\'’]s\b/u', 's', $text) ?? $text;

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? '';
    }

    /**
     * The heading as it should be displayed, if this spelling becomes canonical.
     *
     * Title-cased only when the book shouted it, exactly as
     * SectionDetector::tidyTitle() decides. A mixed-case heading is left as
     * printed -- inventing casing for one would put a name in Eddie's mouth
     * that no book set that way.
     */
    public function display(string $rawHeading): string
    {
        $text = $this->patterns->trimEdges($rawHeading);

        return $this->isAllCaps($text)
            ? mb_convert_case(mb_strtolower(trim($text)), MB_CASE_TITLE, 'UTF-8')
            : $text;
    }

    /**
     * Whether a folded key names a division of a book rather than a drink.
     */
    public function isStopHeading(string $key): bool
    {
        return in_array($key, (array) config('books.drinks.stop_headings'), true);
    }

    private function isAllCaps(string $text): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $text) ?? '';

        if ($letters === '') {
            return false;
        }

        $upper = preg_replace('/[^\p{Lu}]/u', '', $letters) ?? '';

        return mb_strlen($upper) === mb_strlen($letters);
    }
}
