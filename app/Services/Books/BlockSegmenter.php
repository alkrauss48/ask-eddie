<?php

namespace App\Services\Books;

/**
 * Cuts a book's stream into the units that chunking packs.
 *
 * A blank line is the unit boundary, because it is the only paragraph signal
 * this corpus carries and 4,403 of 4,910 pages use one. Per-book median blocks
 * run from 14 to 140 characters and the ninetieth percentile from 92 to 928, so
 * blocks are small enough that a chunk holds several whole ones -- which is what
 * lets a chunk hold several *whole* recipes instead of half of one.
 *
 * Where a single block is longer than a chunk may be, it is subdivided by
 * sentence, then by line, then by word. Every cut is recorded, and a cut that
 * had to fall inside a word is flagged so the command can report it: that
 * should never happen on real text, and if it starts happening it is a bug
 * rather than a fact about the corpus.
 */
class BlockSegmenter
{
    public function __construct(
        private readonly HeadingPatterns $patterns,
        private readonly TokenEstimator $tokens,
    ) {}

    /**
     * @return list<TextBlock>
     */
    public function segment(BookTextStream $stream): array
    {
        $blocks = [];

        foreach ($this->paragraphs($stream->text) as [$text, $start]) {
            foreach ($this->fit($text, $start) as $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * Blank-line separated paragraphs, with their byte offsets.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function paragraphs(string $text): array
    {
        $paragraphs = [];
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            $next = preg_match('/\n[ \t]*\n/u', $text, $matches, PREG_OFFSET_CAPTURE, $offset) === 1
                ? $matches[0][1]
                : $length;

            $slice = substr($text, $offset, $next - $offset);
            $trimmed = trim($slice);

            if ($trimmed !== '') {
                // Offsets track the trimmed text, so a chunk never claims the
                // whitespace between two paragraphs.
                $lead = strlen($slice) - strlen(ltrim($slice));
                $paragraphs[] = [$trimmed, $offset + $lead];
            }

            if ($next >= $length) {
                break;
            }

            $offset = $next + strlen($matches[0][0]);
        }

        return $paragraphs;
    }

    /**
     * Turn one paragraph into blocks no larger than a chunk may be.
     *
     * @return list<TextBlock>
     */
    private function fit(string $text, int $start): array
    {
        $heading = $this->headingOf($text);
        $ceiling = $this->tokenSafeCeiling($text, $this->tokens->contentCharacterCeiling());

        $blocks = [];

        foreach ($this->explode($text, $ceiling) as $index => [$part, $partStart, $hardCut]) {
            $blocks[] = new TextBlock(
                $part,
                $start + $partStart,
                $start + $partStart + strlen($part),
                // Only the first part carries the heading; the rest are its
                // continuation, and the packer keeps them together.
                $index === 0 ? $heading : null,
                $this->lineCount($part),
                $hardCut,
            );
        }

        return $blocks;
    }

    /**
     * Break text down until every part is inside both ceilings.
     *
     * Recursive, because one pass is not enough. Splitting a paragraph uses the
     * paragraph's own average token density, and a single part can be denser
     * than the average it was sized by -- a run of fractions inside otherwise
     * ordinary prose does exactly that. Each part is therefore re-checked and,
     * if it still does not fit, split again against a tighter ceiling.
     *
     * @return list<array{0: string, 1: int, 2: bool}>
     */
    private function explode(string $text, int $ceiling, int $depth = 0): array
    {
        if ($this->fitsWithin($text, $ceiling)) {
            return [[$text, 0, false]];
        }

        $parts = [];

        foreach ($this->subdivide($text, $ceiling) as [$part, $offset, $hardCut]) {
            if ($depth >= 4 || $this->fitsWithin($part, $ceiling)) {
                $parts[] = [$part, $offset, $hardCut];

                continue;
            }

            foreach ($this->explode($part, max(240, (int) ($ceiling * 0.75)), $depth + 1) as [$sub, $subOffset, $subHardCut]) {
                $parts[] = [$sub, $offset + $subOffset, $hardCut || $subHardCut];
            }
        }

        return $parts;
    }

    /**
     * Whether a piece of text is inside the character and token ceilings both.
     */
    private function fitsWithin(string $text, int $ceiling): bool
    {
        return strlen($text) <= $ceiling
            && $this->tokens->estimate($text) <= $this->tokens->contentTokenCeiling();
    }

    /**
     * A character ceiling low enough that this text also fits the token ceiling.
     *
     * Characters and tokens do not track each other evenly across this corpus.
     * A Cafe Royal recipe block is dense with fractions -- "1/2", "1/4" -- and
     * each of those costs more tokens per character than prose does, so a block
     * sized to the character ceiling can still overrun the token budget. Scaling
     * the ceiling by the text's own measured density keeps both limits true
     * without penalising ordinary prose.
     */
    private function tokenSafeCeiling(string $text, int $ceiling): int
    {
        $estimate = $this->tokens->estimate($text);
        $bytes = strlen($text);

        if ($estimate <= 0 || $bytes <= 0) {
            return $ceiling;
        }

        $allowed = (int) floor($this->tokens->contentTokenCeiling() * ($bytes / $estimate));

        // Never shrink below something a sentence can live in.
        return max(240, min($ceiling, $allowed));
    }

    /**
     * Split an oversized paragraph at the best boundary available.
     *
     * @return list<array{0: string, 1: int, 2: bool}>
     */
    private function subdivide(string $text, int $ceiling): array
    {
        $parts = [];
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            $remaining = $length - $offset;

            if ($remaining <= $ceiling) {
                $parts[] = [substr($text, $offset), $offset, false];

                break;
            }

            $window = substr($text, $offset, $ceiling);
            $cut = $this->lastBoundary($window);
            $hardCut = false;

            if ($cut === null) {
                // No sentence, line or word boundary in a whole chunk's worth of
                // text. Real prose never does this; OCR sometimes emits a solid
                // run of characters, and the ceiling still has to hold. The cut
                // is walked back to a character boundary, because a slice taken
                // inside a multibyte character is invalid UTF-8.
                $cut = $this->characterBoundary($text, $offset, $ceiling);
                $hardCut = true;
            }

            $parts[] = [rtrim(substr($text, $offset, $cut)), $offset, $hardCut];
            $offset += $cut;

            // Skip the whitespace the boundary sat on.
            while ($offset < $length && preg_match('/\s/', $text[$offset]) === 1) {
                $offset++;
            }
        }

        return array_values(array_filter($parts, fn (array $part): bool => trim($part[0]) !== ''));
    }

    /**
     * Walk a byte offset back to the start of a UTF-8 character.
     */
    private function characterBoundary(string $text, int $offset, int $length): int
    {
        while ($length > 1 && (ord($text[$offset + $length]) & 0xC0) === 0x80) {
            $length--;
        }

        return $length;
    }

    /**
     * The last usable boundary in a window, preferring the strongest kind.
     */
    private function lastBoundary(string $window): ?int
    {
        foreach ([$this->sentenceBoundary($window), $this->linebreakBoundary($window), $this->wordBoundary($window)] as $candidate) {
            if ($candidate !== null && $candidate > 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The end of the last complete sentence in the window.
     *
     * The abbreviation guard is the part the Python original lacked: it split on
     * every period, so "No. 134", "Mr. Thomas" and "1/2 oz. gin" all became
     * sentence boundaries. It is applied after matching rather than inside the
     * pattern, because PCRE only accepts a fixed-length lookbehind and these
     * abbreviations are not all the same length.
     */
    private function sentenceBoundary(string $window): ?int
    {
        if (preg_match_all('/[.!?]["\'\)\]]?\s+(?=\p{Lu}|\d)/u', $window, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        foreach (array_reverse($matches[0]) as [$separator, $offset]) {
            if (! $this->followsAbbreviation($window, $offset)) {
                return $offset + strlen($separator);
            }
        }

        return null;
    }

    /**
     * Whether the period at the given offset belongs to an abbreviation.
     */
    private function followsAbbreviation(string $window, int $offset): bool
    {
        $preceding = substr($window, 0, $offset);

        if (preg_match('/(\p{L}+)$/u', $preceding, $matches) !== 1) {
            return false;
        }

        $word = $matches[1];

        // A lone capital is an initial: "W. J. Tarling".
        if (mb_strlen($word) === 1 && preg_match('/\p{Lu}/u', $word) === 1) {
            return true;
        }

        return in_array(mb_strtolower($word), [
            'mr', 'mrs', 'ms', 'dr', 'st', 'no', 'vol', 'pp', 'co', 'ltd',
            'etc', 'inc', 'jr', 'sr', 'oz', 'doz', 'ave', 'fig', 'ibid',
        ], true);
    }

    private function linebreakBoundary(string $window): ?int
    {
        $position = strrpos($window, "\n");

        return $position === false ? null : $position + 1;
    }

    private function wordBoundary(string $window): ?int
    {
        if (preg_match_all('/\s+/u', $window, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        $offsets = $matches[0];

        return $offsets === [] ? null : $offsets[count($offsets) - 1][1] + strlen($offsets[count($offsets) - 1][0]);
    }

    /**
     * The heading a block opens with, if it opens with one.
     */
    private function headingOf(string $text): ?HeadingMatch
    {
        $lines = explode("\n", $text);

        return $this->patterns->match($lines[0], $lines[1] ?? null);
    }

    private function lineCount(string $text): int
    {
        return count(array_filter(
            explode("\n", $text),
            fn (string $line): bool => trim($line) !== '',
        ));
    }
}
