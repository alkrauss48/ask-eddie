<?php

namespace App\Services\Books;

use Normalizer;

/**
 * Cleans a single page of extracted text.
 *
 * This is a port of the Python pipeline's normalize() with two deliberate
 * departures, both of which exist because this corpus feeds a citation-bearing
 * RAG index and silently losing a line of a recipe is worse than keeping noise:
 *
 * 1. It never deletes whole lines. The Python dropped lines under three
 *    characters, lines below a 40% alphanumeric ratio, and lines with more than
 *    three apostrophes -- which between them discard "Gin.", "1/2", rule lines,
 *    and most French and Italian text. Judging what is worth keeping needs
 *    surrounding context, so it belongs to chunking, not to normalization.
 *
 * 2. It does not run the Python's "OCR spacing fixes" loop, which joined any
 *    single letter to the following word five times over and turned "a drink"
 *    into "adrink". That regex was compensating for a bad OCR pass; the fix for
 *    bad OCR is better OCR, which this pipeline now does.
 *
 * Normalization is lossless enough to be re-runnable: raw text is retained on
 * every extraction, so bumping self::VERSION and re-normalizing the corpus
 * costs a query rather than another OCR pass.
 */
class PageTextNormalizer
{
    /**
     * Bumped whenever the rules below change, so that stale pages can be found
     * with a query and re-normalized without touching the source PDFs.
     */
    public const VERSION = 2;

    /**
     * Typographic ligatures and the long s, mapped to their component letters.
     */
    private const LIGATURES = [
        "\u{FB00}" => 'ff',
        "\u{FB01}" => 'fi',
        "\u{FB02}" => 'fl',
        "\u{FB03}" => 'ffi',
        "\u{FB04}" => 'ffl',
        "\u{FB05}" => 'st',
        "\u{FB06}" => 'st',
        "\u{017F}" => 's',
    ];

    public function normalize(string $text): NormalizedPage
    {
        $text = $this->toComposedUnicode($text);
        $text = strtr($text, self::LIGATURES);
        $text = $this->stripInvisibleCharacters($text);
        $text = $this->normalizeWhitespaceCharacters($text);

        [$text, $printedPageLabel] = $this->extractPrintedPageLabel($text);

        $text = $this->joinHyphenatedLineBreaks($text);
        $text = $this->collapseWhitespace($text);

        return new NormalizedPage(trim($text), $printedPageLabel);
    }

    /**
     * Compose decomposed sequences so that "Curaçao" is always the same bytes.
     *
     * Tesseract emits combining marks inconsistently, and an accented character
     * written two different ways would split the embeddings for the same word.
     */
    private function toComposedUnicode(string $text): string
    {
        if (! class_exists(Normalizer::class)) {
            return $text;
        }

        return Normalizer::isNormalized($text, Normalizer::FORM_C)
            ? $text
            : (Normalizer::normalize($text, Normalizer::FORM_C) ?: $text);
    }

    /**
     * Remove control characters, zero-width characters, and soft hyphens, all
     * of which are invisible but still count towards similarity and search.
     */
    private function stripInvisibleCharacters(string $text): string
    {
        $text = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u', '', $text) ?? $text;
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;

        return str_replace("\u{00AD}", '', $text);
    }

    /**
     * Fold the various Unicode spaces down to a plain space.
     */
    private function normalizeWhitespaceCharacters(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return preg_replace(
            '/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u',
            ' ',
            $text
        ) ?? $text;
    }

    /**
     * Pull the printed page number off the first or last line and return it
     * separately, rather than deleting it as the Python pipeline did.
     *
     * The printed number is offset from the physical page by the front matter,
     * and it is what a reader needs in order to find a quoted passage, so it is
     * the single most useful citation field this pipeline can capture.
     *
     * @return array{0: string, 1: string|null}
     */
    private function extractPrintedPageLabel(string $text): array
    {
        $lines = explode("\n", $text);
        $label = null;

        // Only the first and last non-blank lines are considered, since that is
        // where a folio sits. Scanning the whole page would strip numbers out
        // of the recipes themselves.
        foreach ($this->edgeLineIndexes($lines) as $index) {
            $candidate = trim($lines[$index]);

            // A line that is nothing but a number, optionally wrapped in dashes
            // or brackets: "42", "- 42 -", "[42]". Roman numerals cover the
            // front matter.
            if (preg_match('/^[\[\(]?\s*[-\x{2014}\x{2013}]?\s*(\d{1,4}|[ivxlcdm]{1,7}|[IVXLCDM]{1,7})\s*[-\x{2014}\x{2013}]?\s*[\]\)]?$/u', $candidate, $matches) === 1) {
                $label ??= $matches[1];
                $lines[$index] = '';

                continue;
            }

            // More often the folio shares its line with the running head, as in
            // "26      ROCHESTER PUNCH." or "MODERN AMERICAN DRINKS.      39".
            // The number is lifted out and the head is left in place.
            foreach ([
                '/^(\d{1,4})\s+(\S.*)$/u' => 2,
                '/^(.*\S)\s+(\d{1,4})$/u' => 1,
            ] as $pattern => $headGroup) {
                if (preg_match($pattern, $candidate, $matches) !== 1) {
                    continue;
                }

                $head = $matches[$headGroup];
                $number = $matches[$headGroup === 2 ? 1 : 2];

                if (! $this->looksLikeRunningHead($head)) {
                    continue;
                }

                $label ??= $number;
                $lines[$index] = $head;

                break;
            }
        }

        return [implode("\n", $lines), $label];
    }

    /**
     * Indexes of the first and last non-blank lines.
     *
     * @param  list<string>  $lines
     * @return list<int>
     */
    private function edgeLineIndexes(array $lines): array
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

        $first = $populated[0];
        $last = $populated[count($populated) - 1];

        return $first === $last ? [$first] : [$first, $last];
    }

    /**
     * Whether a fragment reads like a running head rather than body text.
     *
     * These books set running heads in capitals, which is what separates
     * "26 ROCHESTER PUNCH." from a recipe line beginning "2 dashes of bitters".
     */
    private function looksLikeRunningHead(string $fragment): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $fragment) ?? '';
        $letterCount = mb_strlen($letters);

        if ($letterCount < 3) {
            return false;
        }

        $uppercase = preg_replace('/[^\p{Lu}]/u', '', $letters) ?? '';

        return (mb_strlen($uppercase) / $letterCount) >= 0.6;
    }

    /**
     * Rejoin words split across a line break by the typesetter's hyphenation.
     *
     * Only lowercase-to-lowercase joins are made. "Anglo-\nAmerican" and
     * "Punch-\nBowl" are genuine hyphenated compounds, and welding those
     * together would be a different kind of damage.
     */
    private function joinHyphenatedLineBreaks(string $text): string
    {
        return preg_replace('/(\p{Ll})-[ \t]*\n[ \t]*(\p{Ll})/u', '$1$2', $text) ?? $text;
    }

    /**
     * Collapse runs of spaces and limit blank lines to a single separator.
     */
    private function collapseWhitespace(string $text): string
    {
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;

        return preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
    }
}
