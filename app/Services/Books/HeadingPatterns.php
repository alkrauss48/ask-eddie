<?php

namespace App\Services\Books;

/**
 * Recognises the line that opens a recipe or a division of a book.
 *
 * One class, because two callers have to agree exactly: SectionDetector counts
 * these to decide how a book gets cut, and BlockSegmenter uses them to place
 * the cuts. If they disagreed, a book could be routed down the heading path and
 * then find no headings.
 *
 * Three shapes cover the corpus, and each was read off real pages:
 *
 *   NUMBERED     "128. Gin Sangaree."      Jerry Thomas 1862, Harry Johnson
 *   CAPS_PREFIX  "BLUE LADY 1/2 Blue Curasao (Gamier)."   Cafe Royal 1937
 *   CAPS_LINE    "PUNCHES."                most books, between runs of recipes
 *
 * A trailing period is allowed everywhere. The Python original required that a
 * heading *not* end in one, which disabled detection across most of this
 * corpus, where "GIN SLING." is the normal form.
 */
class HeadingPatterns
{
    public const NUMBERED = 'numbered';

    public const CAPS_PREFIX = 'caps_prefix';

    public const CAPS_LINE = 'caps_line';

    public const TITLE_LINE = 'title_line';

    /**
     * Ingredient and instruction words. A caps run followed by one of these is
     * a shouted ingredient line, not a drink name.
     */
    /**
     * What a bartender is told to do, as opposed to what a section is called.
     */
    private const INSTRUCTION_WORDS = '/\b(shake|strain|stir|frappe|frappé|squeeze|garnish|sweeten|grate|fill|pour|serve|mix)\b/iu';

    private const MEASURE_WORDS = 'dash|dashes|jigger|pony|gill|glass|glassful|spoonful|teaspoonful|tablespoonful|table-spoonful|wine-?glass|drops?|slices?|lumps?|pieces?|parts?|oz|ounces?|quarts?|pints?|bottles?';

    /**
     * Find the headings in a page's lines, keyed by line index.
     *
     * Consecutive matches collapse into the first of the run, for two reasons.
     * A title set over four capitalised lines is one heading, not four. And
     * without it, a dedication page set entirely in capitals reads as a page of
     * fourteen headings -- page 9 of Old Waldorf Bar Days does exactly that --
     * which would misreport a narrative book as densely structured.
     *
     * @param  list<string>  $lines
     * @param  array<int, bool>  $skip  line indexes to ignore, normally the folio zone
     * @return array<int, HeadingMatch>
     */
    public function scan(array $lines, array $skip = []): array
    {
        $headings = [];
        $previousWasHeading = false;

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                $previousWasHeading = false;

                continue;
            }

            if (isset($skip[$index])) {
                continue;
            }

            $match = $this->match($line, $this->nextPopulated($lines, $index));

            if ($match === null) {
                $previousWasHeading = false;

                continue;
            }

            if (! $previousWasHeading) {
                $headings[$index] = $match;
            }

            $previousWasHeading = true;
        }

        return $headings;
    }

    /**
     * The line indexes of a page's folio zone: its first and last non-blank
     * lines, which is where running heads and page numbers sit.
     *
     * @param  list<string>  $lines
     * @return array<int, bool>
     */
    public function folioZone(array $lines): array
    {
        $populated = [];

        foreach ($lines as $index => $line) {
            if (trim($line) !== '') {
                $populated[] = $index;
            }
        }

        if ($populated === []) {
            return [];
        }

        return [
            $populated[0] => true,
            $populated[count($populated) - 1] => true,
        ];
    }

    /**
     * The next non-blank line after the given index, for the lookahead the
     * title-case family needs.
     *
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

    public function match(string $line, ?string $nextLine = null): ?HeadingMatch
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return null;
        }

        return $this->matchNumbered($trimmed)
            ?? $this->matchCapsLine($trimmed)
            ?? $this->matchCapsPrefix($trimmed)
            ?? $this->matchTitleLine($trimmed, $nextLine);
    }

    /**
     * "Whiskey Cocktail." on its own line, above its ingredients.
     *
     * This is the dominant form in about a quarter of the corpus -- Hoffman
     * House 1912, Drinks 1914, Daly's 1903, Modern American Drinks 1900 -- none
     * of which shouts its drink names.
     *
     * A short title-cased line is not evidence of anything on its own: OCR
     * wraps prose into short capitalised fragments constantly, and counting
     * those would report Old Waldorf Bar Days as having seven headings a page.
     * What makes it a heading is what follows it. A recipe name is followed by
     * a measure -- a digit, a fraction, or a word like "dash" -- and a wrapped
     * line of prose is followed by more prose.
     */
    private function matchTitleLine(string $line, ?string $nextLine): ?HeadingMatch
    {
        if ($nextLine === null || mb_strlen($line) > 48) {
            return null;
        }

        // The whole line has to read as a name: capitalised words, no verbs
        // trailing off into a sentence, and at most a closing period.
        if (preg_match('/^\p{Lu}[\p{L}\p{N}\'’\.\- ]*[\p{L}\p{N}\'’\.]$/u', $line) !== 1) {
            return null;
        }

        $words = preg_split('/\s+/u', trim($line, ' .')) ?: [];

        if ($words === [] || count($words) > 6) {
            return null;
        }

        foreach ($words as $word) {
            // Every word either opens with a capital or is a short joining word
            // such as "of", "the", "a la".
            if (preg_match('/^(\p{Lu}|\p{Ll}{1,3}$)/u', $word) !== 1) {
                return null;
            }
        }

        if ($this->letterCount($line) < 3 || $this->looksLikeIngredients($line)) {
            return null;
        }

        return $this->opensARecipe($nextLine)
            ? new HeadingMatch($this->trimEdges($line), self::TITLE_LINE)
            : null;
    }

    /**
     * Whether a line reads like the first measure of a recipe.
     *
     * Public because the drink layer needs the same judgement and must not
     * arrive at it separately. matchCapsLine() flags every caps line
     * sectionLike, so in a book that shouts its drink names -- "GIN SLING."
     * above its ingredients -- sectionLike alone cannot tell a division from a
     * drink. What follows the line can, and this is already how the title-case
     * family decides. A second copy of it would drift, which is the thing this
     * class exists to prevent.
     */
    public function opensARecipe(string $line): bool
    {
        $line = trim($line);

        if (preg_match('/^[\(]?(\d|½|¼|¾|⅓|⅔|⅛)/u', $line) === 1) {
            return true;
        }

        return $this->looksLikeIngredients($line)
            || preg_match('/^\((?:use|take)\b/iu', $line) === 1;
    }

    /**
     * "128. Gin Sangaree." and its OCR casualties.
     *
     * The separator after the number is required, because "1 Wine-glass of
     * brandy" is an ingredient line and would otherwise read as recipe 1. The
     * number may carry a stray letter -- this corpus contains "13u." for 130 --
     * so it is captured for the record but never used for ordering or
     * de-duplication, since the sequence cannot be trusted.
     */
    private function matchNumbered(string $line): ?HeadingMatch
    {
        if (preg_match('/^(\d{1,4}[a-zA-Z]?)\s*[.,]\s+(\p{Lu}.*)$/u', $line, $matches) !== 1) {
            return null;
        }

        $text = trim($matches[2]);

        return new HeadingMatch(
            $text,
            self::NUMBERED,
            $matches[1],
            // "131. TODDIES AND SLINGS" is a division heading that happens to
            // have been numbered along with the recipes around it.
            $this->isShouted($text) && ! $this->looksLikeIngredients($text),
        );
    }

    /**
     * A line that is nothing but a heading: "PUNCHES.", "TODDIES AND SLINGS".
     */
    private function matchCapsLine(string $line): ?HeadingMatch
    {
        if (mb_strlen($line) > 80 || ! $this->isShouted($line) || $this->looksLikeIngredients($line)) {
            return null;
        }

        if (preg_match('/^[\p{Lu}\p{N}\s\'’\-\.,&:;()]+$/u', $line) !== 1) {
            return null;
        }

        // A caps line carrying digits is usually a measure or a guide word such
        // as "COCKTAILS BL-BO"; the latter is handled as a running head.
        return new HeadingMatch($this->trimEdges($line), self::CAPS_LINE, null, true);
    }

    /**
     * "BLUE LADY 1/2 Blue Curasao (Gamier)." -- a drink name sharing its line
     * with the first ingredient, because pdftotext -layout merged the columns.
     */
    private function matchCapsPrefix(string $line): ?HeadingMatch
    {
        $pattern = '/^(\p{Lu}[\p{Lu}\s\'’\-\.&]{2,60}?)(?=\s+(?:\d|\p{Ll}))/u';

        if (preg_match($pattern, $line, $matches) !== 1) {
            return null;
        }

        $text = $this->trimEdges($matches[1]);

        if ($this->letterCount($text) < 3 || $this->looksLikeIngredients($text)) {
            return null;
        }

        // Two words minimum, or one long one. A single short capital such as
        // "A" or "IN" heading a sentence is prose, not a drink.
        if (! str_contains($text, ' ') && mb_strlen($text) < 4) {
            return null;
        }

        $remainder = trim(mb_substr($line, mb_strlen($matches[1])));

        // The rest of the line has to look like the start of a recipe. Without
        // this, any sentence opening on a proper noun reads as a heading.
        if (preg_match('/^(\d|\p{Ll})/u', $remainder) !== 1) {
            return null;
        }

        return new HeadingMatch($text, self::CAPS_PREFIX);
    }

    /**
     * Whether a line reads as part of a recipe rather than as a title.
     *
     * A running head names a division of a book. An instruction does not, and
     * some of these books repeat one at the foot of every page: U.K.B.G. 1937
     * ends page after page with "Shake and strain into cocktail glass", which
     * repeats often enough to look exactly like a running head and put a
     * nonsense section title into every citation on those pages.
     */
    public function looksLikeRecipeLine(string $line): bool
    {
        return $this->looksLikeIngredients($line)
            || preg_match(self::INSTRUCTION_WORDS, $line) === 1;
    }

    /**
     * Trim punctuation and space from the ends of a heading.
     *
     * Deliberately not trim() with a character list. That list is matched byte
     * by byte, so including an em dash -- three bytes in UTF-8 -- lets trim()
     * shear a single byte off an unrelated multibyte character and hand back a
     * broken string. In this corpus that produced a heading ending in half of
     * the "ç" in "Curaçao", which Postgres then rejected as invalid UTF-8 in
     * the middle of a 500-row insert.
     */
    public function trimEdges(string $text): string
    {
        return preg_replace('/^[\s.,:;|*\x{2013}\x{2014}\-]+|[\s.,:;|*\x{2013}\x{2014}\-]+$/u', '', $text) ?? $text;
    }

    /**
     * Whether at least 60% of a fragment's letters are capitals.
     *
     * The same threshold PageTextNormalizer uses to tell a running head from a
     * recipe line, kept in step deliberately.
     */
    private function isShouted(string $text): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $text) ?? '';
        $count = mb_strlen($letters);

        if ($count < 3) {
            return false;
        }

        $upper = preg_replace('/[^\p{Lu}]/u', '', $letters) ?? '';

        return (mb_strlen($upper) / $count) >= 0.6;
    }

    private function looksLikeIngredients(string $text): bool
    {
        return preg_match('/\b('.self::MEASURE_WORDS.')\b/iu', $text) === 1;
    }

    private function letterCount(string $text): int
    {
        return mb_strlen(preg_replace('/[^\p{L}]/u', '', $text) ?? '');
    }
}
