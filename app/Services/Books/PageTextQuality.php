<?php

namespace App\Services\Books;

/**
 * Scores a page of text on how much of it looks like real language.
 *
 * The corpus ships with an embedded text layer from an older OCR pass whose
 * output ranges from clean prose to "KOCllESTKli rUKCll." for ROCHESTER PUNCH.
 * This scorer separates the two so a page can be routed, and so the two stored
 * candidates for a page can be compared.
 *
 * It is deliberately dictionary-free: the corpus is full of obscure French and
 * Spanish liqueur names and archaic spellings, so a word list would reject as
 * much good text as bad.
 *
 * Its limits are worth stating plainly, because both were measured against real
 * pages of this corpus rather than assumed.
 *
 * It detects malformed words, not wrong ones. "Snuterne" for "Sauterne" is
 * correctly cased, vowel-balanced and plausibly long, so it scores as clean.
 * No dictionary-free heuristic catches that.
 *
 * It also cannot finely separate a lightly damaged page from a clean one. Page
 * 30 of the 1862 Jerry Thomas scores 0.944 from its embedded text layer and
 * 0.971 from fresh OCR, even though the text layer renders that page's heading
 * as "KOCllESTKli rUKCll." -- three ruined tokens out of sixty-six cannot move
 * a page-level average far. What the scale does separate reliably is severity:
 *
 *   0.58  library stamps and scanner noise
 *   0.84  index pages, dominated by dot leaders
 *   0.94  readable prose carrying a mangled heading
 *   0.97  clean prose
 *
 * So this score is used to rank the two stored candidates for a page and to
 * flag catastrophic pages for review. It is not trusted to decide whether a
 * good-looking text layer can be believed -- that is why the pipeline OCRs
 * every page by default instead of gating on this number.
 */
class PageTextQuality
{
    /**
     * Below this many tokens a page is too sparse to judge -- a plate caption
     * or a one-line recipe would otherwise be mistaken for garbage.
     */
    private const MINIMUM_TOKENS = 8;

    private const VOWELS = 'aeiouyàáâãäåèéêëìíîïòóôõöùúûüýÿæœ';

    public function score(string $text): QualityScore
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return QualityScore::unscorable('empty');
        }

        $tokens = $this->tokenize($trimmed);

        if (count($tokens) < self::MINIMUM_TOKENS) {
            return QualityScore::unscorable('insufficient_text');
        }

        $plausible = 0;

        foreach ($tokens as $token) {
            if ($this->isPlausibleWord($token)) {
                $plausible++;
            }
        }

        $plausibleRatio = $plausible / count($tokens);
        $alphaRatio = $this->alphabeticRatio($trimmed);
        $lengthFit = $this->meanLengthFit($tokens);

        $score = (0.60 * $plausibleRatio)
            + (0.20 * min(1.0, $alphaRatio / 0.85))
            + (0.20 * $lengthFit);

        return new QualityScore(
            score: round($score, 4),
            breakdown: [
                'tokens' => count($tokens),
                'plausible_tokens' => $plausible,
                'plausible_ratio' => round($plausibleRatio, 4),
                'alpha_ratio' => round($alphaRatio, 4),
                'length_fit' => round($lengthFit, 4),
            ],
        );
    }

    /**
     * Split into candidate words, discarding surrounding punctuation and
     * anything with no letter in it at all.
     *
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $tokens = [];

        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            $candidate = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $candidate) ?? '';

            if ($candidate !== '' && preg_match('/\p{L}/u', $candidate) === 1) {
                $tokens[] = $candidate;
            }
        }

        return $tokens;
    }

    /**
     * Decide whether a single token looks like a word a typesetter set, rather
     * than something a scanner invented.
     */
    private function isPlausibleWord(string $token): bool
    {
        if (mb_strlen($token) > 22) {
            return false;
        }

        // Letters and digits welded together: "Cura9oa", "i8n", "PLA4".
        if (preg_match('/\p{L}/u', $token) === 1 && preg_match('/\p{N}/u', $token) === 1) {
            return false;
        }

        // Hyphens and apostrophes join whole words -- "Bar-Tender", "d'orange",
        // "calves-feet" -- so each part is judged on its own terms.
        $parts = preg_split('/[\'\x{2019}\x{2018}\-]/u', $token, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return false;
        }

        foreach ($parts as $part) {
            if (! $this->isPlausiblePart($part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Judge one indivisible word part.
     */
    private function isPlausiblePart(string $part): bool
    {
        // Roman numerals are legitimate in front matter and would otherwise
        // fail the vowel test below.
        if (preg_match('/^[IVXLCDM]+$/u', $part) === 1) {
            return true;
        }

        // Case that flips inside a word is the signature failure of the older
        // OCR pass -- "KOCllESTKli", "rUKCll", "LliRARV" -- and essentially
        // never occurs in real text.
        if (preg_match('/^(\p{Ll}+|\p{Lu}+|\p{Lu}\p{Ll}*)$/u', $part) !== 1) {
            return false;
        }

        $letters = preg_replace('/[^\p{L}]/u', '', $part) ?? '';
        $letterCount = mb_strlen($letters);

        if ($letterCount === 0) {
            return false;
        }

        $vowels = $this->vowelCount($letters);

        if ($letterCount >= 3 && $vowels === 0) {
            return false;
        }

        // The floor sits below "strengths", which is one vowel in nine letters
        // and the least vowel-dense ordinary English word likely to turn up.
        if ($letterCount >= 4) {
            $vowelRatio = $vowels / $letterCount;

            if ($vowelRatio < 0.10 || $vowelRatio > 0.80) {
                return false;
            }
        }

        return $this->longestConsonantRun($letters) <= 5;
    }

    private function vowelCount(string $letters): int
    {
        $count = 0;
        $lowered = mb_strtolower($letters);

        foreach (preg_split('//u', $lowered, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if (mb_strpos(self::VOWELS, $character) !== false) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * "strengths" has a run of five, so anything longer is almost certainly
     * scanner noise rather than English, French, Spanish or Italian.
     */
    private function longestConsonantRun(string $letters): int
    {
        $longest = 0;
        $current = 0;
        $lowered = mb_strtolower($letters);

        foreach (preg_split('//u', $lowered, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if (mb_strpos(self::VOWELS, $character) === false) {
                $current++;
                $longest = max($longest, $current);
            } else {
                $current = 0;
            }
        }

        return $longest;
    }

    private function alphabeticRatio(string $text): float
    {
        $withoutWhitespace = preg_replace('/\s+/u', '', $text) ?? '';
        $total = mb_strlen($withoutWhitespace);

        if ($total === 0) {
            return 0.0;
        }

        $letters = preg_replace('/[^\p{L}]/u', '', $withoutWhitespace) ?? '';

        return mb_strlen($letters) / $total;
    }

    /**
     * English prose averages a shade under five characters per word; text that
     * drifts far from that is usually dot leaders or run-together garbage.
     *
     * @param  list<string>  $tokens
     */
    private function meanLengthFit(array $tokens): float
    {
        $total = 0;

        foreach ($tokens as $token) {
            $total += mb_strlen($token);
        }

        $mean = $total / count($tokens);

        return max(0.0, 1.0 - min(1.0, abs($mean - 4.7) / 4.0));
    }
}
