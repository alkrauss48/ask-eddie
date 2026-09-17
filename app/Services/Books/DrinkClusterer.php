<?php

namespace App\Services\Books;

/**
 * Decides which folded keys are the same drink.
 *
 * Exact key match is the only merge that runs by default, and that is not
 * timidity: the fold already absorbs case, punctuation, diacritics, articles
 * and the trailing period, which is the bulk of the variation in this corpus.
 *
 * Anything beyond it is opt-in, because a wrong merge is the one failure this
 * layer can produce that survives every check made of it. If "Brandy Sour" and
 * "Brandy Soup" fold together, the survey hands Eddie a row named Brandy Sour
 * carrying a real book, a real page and a real byte offset -- on which the word
 * printed is "Soup". The offsets verify, the citation resolves, and a guest
 * cannot tell. That is worse than an invented citation, which at least fails
 * when someone goes looking.
 *
 * One edit on keys of six characters or more catches the OCR substitution
 * ("BLUE LADV") and little else. It also merges brandysour and brandysoup, and
 * there is no threshold that catches the first and not the second -- which is
 * why the flag defaults off, why every merge leaves a receipt in
 * drinks.aliases, and why books:drinks --merges exists to be read before it is
 * ever turned on.
 */
class DrinkClusterer
{
    public function __construct(private readonly DrinkNameNormalizer $normalizer) {}

    /**
     * Resolve a folded key to the key it should be stored under.
     *
     * Configuration wins over the algorithm in both directions: an alias forces
     * a merge the clusterer would not make, and a split forbids one it would.
     *
     * $known is keyed rather than a list so that the common path -- a key
     * already seen, or fuzzy matching switched off -- is a hash lookup. This
     * runs once per printed heading in the corpus against a set of thousands of
     * drinks, and a linear scan there is tens of millions of comparisons for a
     * question nobody asked.
     *
     * @param  array<string, bool>  $known  keys already assigned, in the order seen
     */
    public function resolve(string $key, array $known): string
    {
        if (($forced = $this->forcedAlias($key)) !== null) {
            return $forced;
        }

        if (isset($known[$key]) || ! $this->fuzzyEnabled()) {
            return $key;
        }

        return $this->nearest($key, array_keys($known)) ?? $key;
    }

    /**
     * The canonical key a configured alias points at, if this is one.
     */
    public function forcedAlias(string $key): ?string
    {
        foreach ((array) config('books.drinks.aliases') as $canonical => $variants) {
            if (in_array($key, (array) $variants, true)) {
                return (string) $canonical;
            }
        }

        return null;
    }

    /**
     * Whether two keys are forbidden from merging, however close they look.
     */
    public function isSplit(string $key, string $other): bool
    {
        foreach ((array) config('books.drinks.splits') as $pair) {
            $pair = (array) $pair;

            if (in_array($key, $pair, true) && in_array($other, $pair, true)) {
                return true;
            }
        }

        return false;
    }

    private function fuzzyEnabled(): bool
    {
        return (bool) config('books.drinks.fuzzy.enabled');
    }

    /**
     * The closest known key within the edit budget, if there is exactly one.
     *
     * levenshtein() is byte-based and caps at 255 bytes, which is why it runs
     * on folded keys -- stripped of punctuation and of every multibyte
     * character Str::ascii() could fold away -- rather than on raw headings.
     *
     * @param  list<string>  $known
     */
    private function nearest(string $key, array $known): ?string
    {
        $minimum = (int) config('books.drinks.fuzzy.min_key_length');
        $budget = (int) config('books.drinks.fuzzy.max_edits');

        if (strlen($key) < $minimum) {
            return null;
        }

        foreach ($known as $candidate) {
            if (strlen($candidate) < $minimum || $this->isSplit($key, $candidate)) {
                continue;
            }

            // A length gap wider than the budget cannot be closed, and skipping
            // those first keeps this from being O(n) levenshtein calls per key
            // across a corpus of thousands of names.
            if (abs(strlen($candidate) - strlen($key)) > $budget) {
                continue;
            }

            if (levenshtein($key, $candidate) <= $budget) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether a folded key names a division of a book rather than a drink.
     */
    public function isStopHeading(string $key): bool
    {
        return $this->normalizer->isStopHeading($key);
    }
}
