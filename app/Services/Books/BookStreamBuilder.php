<?php

namespace App\Services\Books;

use App\Enums\PageStatus;
use App\Models\Book;
use App\Models\BookPage;
use Illuminate\Support\Collection;

/**
 * Joins a book's pages into one stream, keeping an index back to each page.
 *
 * Three things happen here that cannot happen one page at a time:
 *
 * 1. A word split across a page turn is rejoined. PageTextNormalizer already
 *    does this within a page, but by construction it cannot see across the
 *    seam, and 127 pages in this corpus end mid-word.
 *
 * 2. A proven running head is removed. Which heads are proven is
 *    SectionDetector's judgement, recorded on the sections it emits; the page
 *    rows keep the head, so nothing is lost.
 *
 * 3. A bracketed folio such as "[ 108 |" is lifted out and fed back as an
 *    observed page label. Phase 1's pattern is stricter and leaves these in
 *    place, so they would otherwise sit in the middle of a chunk and go to
 *    waste as citation evidence.
 */
class BookStreamBuilder
{
    /**
     * A line that is nothing but a page number, however the scanner mangled the
     * brackets around it.
     */
    private const FOLIO_ARTIFACT = '/^[\[\(\|\{]{0,2}\s*(\d{1,4})\s*[\]\)\|\}]{0,2}$/u';

    /**
     * Sentinel for the one join that also removes a character.
     */
    private const JOIN_HYPHENATED = "\0hyphen";

    /**
     * @param  Collection<int, BookPage>  $pages
     */
    public function build(
        Book $book,
        Collection $pages,
        DetectedStructure $structure,
        PageLabelIndex $labels,
    ): BookTextStream {
        $pages = $pages->sortBy('page_number')->values();

        // First pass: clean each page and harvest any folio the cleaning
        // uncovered, so that the label index is complete before it is consulted.
        $contributions = [];

        foreach ($pages as $page) {
            $contributions[(int) $page->page_number] = $this->contribution($page, $structure, $labels);
        }

        // Second pass: concatenate, recording where each page landed.
        $text = '';
        $spans = [];

        foreach ($contributions as $pageNumber => $contribution) {
            if ($contribution === '') {
                $spans[] = new PageSpan(
                    $pageNumber,
                    strlen($text),
                    strlen($text),
                    ...$this->labelFor($labels, $pageNumber),
                );

                continue;
            }

            if ($text !== '') {
                $separator = $this->separator($text, $contribution);

                if ($separator === self::JOIN_HYPHENATED) {
                    // The typesetter's hyphen goes with the line break that
                    // caused it. Dropping a byte shortens the page that just
                    // ended, so its recorded span has to shrink with it or every
                    // offset after this point would be wrong by one.
                    $text = substr($text, 0, -1);

                    if ($spans !== []) {
                        $spans[count($spans) - 1]->end--;
                    }

                    $separator = '';
                }

                $text .= $separator;
            }

            $start = strlen($text);
            $text .= $contribution;

            $spans[] = new PageSpan(
                $pageNumber,
                $start,
                strlen($text),
                ...$this->labelFor($labels, $pageNumber),
            );
        }

        return new BookTextStream($text, $spans, hash('sha256', $text));
    }

    /**
     * @return array{0: string|null, 1: bool}
     */
    private function labelFor(PageLabelIndex $labels, int $pageNumber): array
    {
        $label = $labels->labelFor($pageNumber);

        return [$label?->value, $label?->estimated ?? false];
    }

    /**
     * How two pages are joined.
     *
     * Not always a blank line. A blank line is the only paragraph signal this
     * corpus has -- 4,403 of 4,910 pages separate paragraphs with one -- so
     * putting a blank line at every page seam would force a chunk boundary at
     * every page end, re-severing exactly the sentences that assembling the
     * book whole exists to protect. The Python original joined every page with
     * a blank line and then collapsed runs, which lost the distinction
     * entirely.
     */
    private function separator(string $text, string $next): string
    {
        // "cock-" followed by "tail": one word, wrongly broken by the
        // typesetter. Only lowercase joins lowercase, because "Anglo-\nAmerican"
        // is a genuine compound and welding those together is its own damage --
        // the same rule PageTextNormalizer applies within a page.
        if (preg_match('/\p{Ll}-$/u', $text) === 1 && preg_match('/^\p{Ll}/u', $next) === 1) {
            return self::JOIN_HYPHENATED;
        }

        // A sentence still in progress continues with a single space.
        if (preg_match('/[\p{Ll},;:]$/u', $text) === 1) {
            return ' ';
        }

        return "\n\n";
    }

    /**
     * One page's text, with its head and folio debris removed.
     */
    private function contribution(BookPage $page, DetectedStructure $structure, PageLabelIndex $labels): string
    {
        if ($page->status === PageStatus::Blank || trim((string) $page->text) === '') {
            return '';
        }

        $lines = explode("\n", (string) $page->text);
        $head = $structure->headFor((int) $page->page_number);
        $edges = $this->edgeIndexes($lines);

        foreach ($edges as $index) {
            $line = trim($lines[$index]);

            if ($line === '') {
                continue;
            }

            if ($head !== null && $line === trim($head)) {
                $lines[$index] = '';

                continue;
            }

            if (preg_match(self::FOLIO_ARTIFACT, $line, $matches) === 1) {
                $labels->observe((int) $page->page_number, $matches[1]);
                $lines[$index] = '';
            }
        }

        // The hyphen join has to run again here, because removing a head can
        // bring two body lines together.
        $text = implode("\n", $lines);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * The first and last non-blank line indexes: the folio zone.
     *
     * Scanning the whole page would strip numbers out of the recipes, which is
     * the same reason PageTextNormalizer limits itself to these two lines.
     *
     * @param  list<string>  $lines
     * @return list<int>
     */
    private function edgeIndexes(array $lines): array
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
}
