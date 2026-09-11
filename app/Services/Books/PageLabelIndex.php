<?php

namespace App\Services\Books;

use App\Models\BookPage;
use Illuminate\Support\Collection;

/**
 * Works out what page number is printed on a page the scanner could not read.
 *
 * Only 61% of the pages in this corpus carry a folio the extractor could see,
 * and the shortfall is worst in the books that need it most: Cafe Royal 1937
 * yields 13 folios across 265 pages, Famous New Orleans 1938 just two. The rest
 * are inferred here, and every inference is flagged.
 *
 * Two things the corpus taught this class, both of them load-bearing:
 *
 * 1. A book is not one numbering series. When It's Cocktail Time in Cuba runs
 *    at an offset of -17 through physical page 29 and -18 from page 31, because
 *    an unnumbered plate is bound in between. A single book-wide offset would
 *    shift every citation after the plate.
 *
 * 2. An isolated folio is usually a misread. Old Waldorf Bar Days offers "1931"
 *    on its title page and "229" on page 13, against 220 pages agreeing on an
 *    offset of -12. Cafe Royal's 13 folios include "1937", "C" and "0", and no
 *    two of them agree on anything. So a series must show a run of consecutive
 *    observations at one offset before it is believed, and a book with no such
 *    run reports no printed page at all rather than a guess.
 */
class PageLabelIndex
{
    /**
     * @var array<int, string>
     */
    private array $observed = [];

    /**
     * @var list<LabelSeries>|null
     */
    private ?array $series = null;

    private bool $romanLowercase = false;

    /**
     * @param  Collection<int, BookPage>  $pages
     */
    public static function forBook(Collection $pages): self
    {
        $index = new self;

        foreach ($pages as $page) {
            if ($page->printed_page_label !== null && trim($page->printed_page_label) !== '') {
                $index->observe((int) $page->page_number, $page->printed_page_label);
            }
        }

        return $index;
    }

    /**
     * Record a folio, including one discovered while assembling the text.
     *
     * The stream builder finds bracketed folios such as "[ 108 |" that the page
     * normalizer's stricter pattern leaves in place. Those go here rather than
     * back onto the page row: book_pages holds what was observed, and
     * overwriting observation with inference is the corruption this whole
     * pipeline exists to avoid.
     */
    public function observe(int $pageNumber, string $label): void
    {
        $this->observed[$pageNumber] = trim($label);
        $this->series = null;
    }

    /**
     * @return array<int, string>
     */
    public function observations(): array
    {
        ksort($this->observed);

        return $this->observed;
    }

    /**
     * @return list<LabelSeries>
     */
    public function series(): array
    {
        return $this->series ??= $this->buildSeries();
    }

    public function labelFor(int $pageNumber): ?PrintedLabel
    {
        $series = $this->series();

        if ($series === []) {
            return null;
        }

        $covering = null;

        foreach ($series as $candidate) {
            if ($candidate->covers($pageNumber)) {
                $covering = $candidate;

                break;
            }
        }

        if ($covering !== null) {
            $implied = $covering->labelFor($pageNumber);

            if ($implied === null) {
                return null;
            }

            $implied = $this->render($covering, $implied);
            $observed = $this->observed[$pageNumber] ?? null;

            // Inside a series the offset is uniform, so an interior page is
            // corroborated from both sides whether or not its own folio was
            // legible. A folio that contradicts its own series is a misread,
            // and overriding it is flagged rather than hidden.
            return $observed === null || $this->matches($observed, $implied)
                ? PrintedLabel::observed($implied)
                : PrintedLabel::inferred($implied);
        }

        return $this->extrapolate($pageNumber, $series);
    }

    /**
     * Apply the nearest series to a page that sits outside all of them.
     *
     * A page between two series that disagree -- Cuba's page 30, sitting on the
     * plate between the -17 and -18 runs -- takes the preceding series, because
     * the numbering it belongs to is the one that has not changed yet.
     */
    private function extrapolate(int $pageNumber, array $series): ?PrintedLabel
    {
        $limit = (int) config('books.chunking.label_extrapolation_max_pages');
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($series as $candidate) {
            $distance = $candidate->distanceTo($pageNumber);

            // Strictly less than, so that a preceding series wins a tie against
            // the one that follows it.
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        if ($best === null || $bestDistance > $limit) {
            return null;
        }

        $implied = $best->labelFor($pageNumber);

        return $implied === null ? null : PrintedLabel::inferred($this->render($best, $implied));
    }

    /**
     * @return list<LabelSeries>
     */
    private function buildSeries(): array
    {
        $runs = [];
        $current = null;

        foreach ($this->observations() as $pageNumber => $label) {
            $reading = $this->read($label);

            if ($reading === null) {
                continue;
            }

            [$script, $number] = $reading;
            $offset = $number - $pageNumber;

            if ($current !== null && $current['script'] === $script && $current['offset'] === $offset) {
                $current['page_to'] = $pageNumber;
                $current['observations']++;

                continue;
            }

            if ($current !== null) {
                $runs[] = $current;
            }

            $current = [
                'script' => $script,
                'offset' => $offset,
                'page_from' => $pageNumber,
                'page_to' => $pageNumber,
                'observations' => 1,
            ];
        }

        if ($current !== null) {
            $runs[] = $current;
        }

        $minimum = max(1, (int) config('books.chunking.label_series_min_run'));

        $kept = array_values(array_filter(
            $runs,
            fn (array $run): bool => $run['observations'] >= $minimum,
        ));

        return $this->merge($kept);
    }

    /**
     * Rejoin runs that a stray misread split in two.
     *
     * A single junk folio in the middle of a good run breaks it into two runs
     * with the same offset. Once the junk has been dropped for standing alone,
     * the halves describe one series again.
     *
     * @param  list<array{script: string, offset: int, page_from: int, page_to: int, observations: int}>  $runs
     * @return list<LabelSeries>
     */
    private function merge(array $runs): array
    {
        $series = [];

        foreach ($runs as $run) {
            $previous = $series === [] ? null : $series[count($series) - 1];

            if ($previous !== null && $previous->script === $run['script'] && $previous->offset === $run['offset']) {
                $previous->pageTo = $run['page_to'];
                $previous->observations += $run['observations'];

                continue;
            }

            $series[] = new LabelSeries(
                $run['script'],
                $run['offset'],
                $run['page_from'],
                $run['page_to'],
                $run['observations'],
            );
        }

        return $series;
    }

    /**
     * Match the book's own casing for front-matter numerals.
     */
    private function render(LabelSeries $series, string $label): string
    {
        return $series->script === 'roman' && $this->romanLowercase
            ? strtolower($label)
            : $label;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private function read(string $label): ?array
    {
        if (preg_match('/^\d{1,4}$/', $label) === 1) {
            return ['arabic', (int) $label];
        }

        $roman = RomanNumeral::toInteger($label);

        if ($roman !== null) {
            $this->romanLowercase = $this->romanLowercase || $label === strtolower($label);

            return ['roman', $roman];
        }

        return null;
    }

    /**
     * Whether an observed folio and the one its series implies are the same.
     */
    private function matches(string $observed, string $implied): bool
    {
        return strcasecmp($observed, $implied) === 0;
    }

    /**
     * Whether this book sets its front-matter numerals in lower case.
     */
    public function prefersLowercaseRoman(): bool
    {
        $this->series();

        return $this->romanLowercase;
    }
}
