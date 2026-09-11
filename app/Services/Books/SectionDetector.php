<?php

namespace App\Services\Books;

use App\Enums\ChunkStrategy;
use App\Enums\SectionKind;
use App\Models\Book;
use App\Models\BookPage;
use Illuminate\Support\Collection;

/**
 * Reads a book's structure off its own running heads.
 *
 * This is the strongest free signal in the corpus. Old Waldorf Bar Days runs to
 * 253 pages but only 53 distinct first lines, and the recurring ones are its
 * chapters over contiguous page ranges -- "Cocktails" pages 129 to 185,
 * "Glossary" 243 to 253 -- alongside the book's own title on 103 versos, which
 * carries no structure at all and would otherwise be repeated inside 103 chunks.
 *
 * Nothing here is per-book: the same measurements run over all 28 and the
 * thresholds are read from configuration. Where the evidence is thin the answer
 * is a null title rather than a guess, because a confidently wrong chapter name
 * is worse in a citation than no chapter name.
 */
class SectionDetector
{
    /**
     * Bumped when these rules change, so that stale sections can be found with
     * a query. Sections are always derived, never authored.
     */
    public const VERSION = 1;

    public function __construct(private readonly HeadingPatterns $patterns) {}

    /**
     * @param  Collection<int, BookPage>  $pages  extracted pages, any order
     */
    public function detect(Book $book, Collection $pages): DetectedStructure
    {
        $pages = $pages->sortBy('page_number')->values();

        if ($pages->isEmpty()) {
            return new DetectedStructure(
                [new DetectedSection(null, SectionKind::Body, 1, 1)],
                [],
                HeadEdge::None,
                ChunkStrategy::Packing,
            );
        }

        $edge = $this->pickEdge($pages);
        $heads = $this->headsAt($pages, $edge);
        $clusters = $this->cluster($heads);

        [$sections, $headByPage] = $this->classify($book, $pages, $clusters);

        $headingsPerPage = $this->headingDensity($pages);

        return new DetectedStructure(
            $sections,
            $headByPage,
            $edge,
            $this->strategyFor($book, $headingsPerPage),
            $headingsPerPage,
        );
    }

    /**
     * How densely a book carries recipe or division headings, per page.
     *
     * The folio zone is excluded, because a running head is not a heading, and
     * consecutive heading lines collapse to one -- see HeadingPatterns::scan().
     */
    public function headingDensity(Collection $pages): float
    {
        $headings = 0;
        $counted = 0;

        foreach ($pages as $page) {
            $text = (string) $page->text;

            if (trim($text) === '') {
                continue;
            }

            $counted++;
            $lines = explode("\n", $text);
            $headings += count($this->patterns->scan($lines, $this->patterns->folioZone($lines)));
        }

        return $counted === 0 ? 0.0 : $headings / $counted;
    }

    /**
     * Whether this book's chunks are anchored to headings or simply packed.
     *
     * A measured decision rather than a hand-assigned one, with an escape hatch
     * in configuration for a book the measurement gets wrong.
     */
    public function strategyFor(Book $book, float $headingsPerPage): ChunkStrategy
    {
        $override = config('books.chunking.strategy_overrides.'.$book->slug);

        if (is_string($override)) {
            return ChunkStrategy::from($override);
        }

        return $headingsPerPage >= (float) config('books.chunking.headings_per_page_min')
            ? ChunkStrategy::Headings
            : ChunkStrategy::Packing;
    }

    /**
     * Which end of the page carries the running head.
     *
     * Worth measuring rather than assuming: most of these books head the top of
     * the page, but the head is not always there, and Jerry Thomas's top line is
     * the name of a recipe rather than a section.
     *
     * @param  Collection<int, BookPage>  $pages
     */
    private function pickEdge(Collection $pages): HeadEdge
    {
        $top = $this->repetition($this->headsAt($pages, HeadEdge::Top));
        $bottom = $this->repetition($this->headsAt($pages, HeadEdge::Bottom));

        if (max($top, $bottom) < 0.15) {
            return HeadEdge::None;
        }

        return $top >= $bottom ? HeadEdge::Top : HeadEdge::Bottom;
    }

    /**
     * The share of pages whose edge line recurs elsewhere in the book.
     *
     * Measured twice, and the stronger reading wins. Exact repetition finds a
     * chapter head or a book title. But a guide word is near-unique by design --
     * Cafe Royal's "COCKTAILS BL-BO" changes on almost every page -- so exact
     * repetition barely sees it, and the running head of a whole book would go
     * undetected. Reducing each head to its stem first asks the question that
     * actually matters: do these lines share one?
     *
     * @param  array<int, string>  $heads
     */
    private function repetition(array $heads): float
    {
        if ($heads === []) {
            return 0.0;
        }

        return max(
            $this->repeatedShare($heads, fn (string $head): string => $this->canonicalize($head)),
            $this->repeatedShare($heads, fn (string $head): string => $this->canonicalize(
                $this->guideWordStem($head) ?? $head,
            )),
        );
    }

    /**
     * @param  array<int, string>  $heads
     * @param  callable(string): string  $key
     */
    private function repeatedShare(array $heads, callable $key): float
    {
        $counts = [];

        foreach ($heads as $head) {
            $canonical = $key($head);
            $counts[$canonical] = ($counts[$canonical] ?? 0) + 1;
        }

        $repeated = 0;

        foreach ($counts as $count) {
            if ($count > 1) {
                $repeated += $count;
            }
        }

        return $repeated / count($heads);
    }

    /**
     * Each page's candidate head line, keyed by page number.
     *
     * @param  Collection<int, BookPage>  $pages
     * @return array<int, string>
     */
    private function headsAt(Collection $pages, HeadEdge $edge): array
    {
        if ($edge === HeadEdge::None) {
            return [];
        }

        $heads = [];

        foreach ($pages as $page) {
            $lines = array_values(array_filter(
                explode("\n", (string) $page->text),
                fn (string $line): bool => trim($line) !== '',
            ));

            if ($lines === []) {
                continue;
            }

            $line = trim($edge === HeadEdge::Top ? $lines[0] : $lines[count($lines) - 1]);

            // A head has to read like one. Numbers, single letters and stray
            // marks are folio debris, and an instruction repeated at the foot
            // of every page is a recipe line -- neither is structure.
            if (mb_strlen($line) > 80 || $this->letterCount($line) < 3) {
                continue;
            }

            if ($this->patterns->looksLikeRecipeLine($line)) {
                continue;
            }

            $heads[(int) $page->page_number] = $line;
        }

        return $heads;
    }

    /**
     * Group the head lines, folding OCR variants of the same head together.
     *
     * "Old Waldotf Bar Days" is one substitution away from "Old Waldorf Bar
     * Days" on a 17-character key, so an edit-distance budget proportional to
     * length absorbs it into the larger cluster while keeping genuinely
     * different heads apart.
     *
     * @param  array<int, string>  $heads
     * @return list<array{key: string, title: string, pages: list<int>, variants: array<string, list<int>>}>
     */
    private function cluster(array $heads): array
    {
        $byKey = [];

        foreach ($heads as $pageNumber => $head) {
            $key = $this->canonicalize($head);

            if ($key === '') {
                continue;
            }

            $byKey[$key]['title'] ??= $head;
            $byKey[$key]['pages'][] = $pageNumber;
            $byKey[$key]['variants'][$head][] = $pageNumber;
        }

        // Largest first, so that variants fold into the dominant spelling.
        uasort($byKey, fn (array $a, array $b): int => count($b['pages']) <=> count($a['pages']));

        $clusters = [];

        foreach ($byKey as $key => $group) {
            $target = null;

            foreach ($clusters as $index => $cluster) {
                if ($this->withinEditBudget($key, $cluster['key'])) {
                    $target = $index;

                    break;
                }
            }

            if ($target === null) {
                $clusters[] = [
                    'key' => $key,
                    'title' => $group['title'],
                    'pages' => $group['pages'],
                    'variants' => $group['variants'],
                ];

                continue;
            }

            $clusters[$target]['pages'] = array_merge($clusters[$target]['pages'], $group['pages']);
            $clusters[$target]['variants'] += $group['variants'];
        }

        foreach ($clusters as $index => $cluster) {
            sort($clusters[$index]['pages']);
        }

        return $clusters;
    }

    /**
     * Turn head clusters into sections, and decide which heads to remove.
     *
     * Removal is the one place chunking discards text, so it is only done where
     * the head is proven redundant, and every removed spelling is recorded on
     * the section it came from. The page rows keep the head either way, so the
     * decision is always reversible without touching a PDF.
     *
     * @param  Collection<int, BookPage>  $pages
     * @param  list<array{key: string, title: string, pages: list<int>, variants: array<string, list<int>>}>  $clusters
     * @return array{0: list<DetectedSection>, 1: array<int, string>}
     */
    private function classify(Book $book, Collection $pages, array $clusters): array
    {
        $total = max(1, $pages->count());
        $bookKey = $this->canonicalize((string) $book->title);
        $sections = [];
        $headByPage = [];

        // A book whose heads are nearly all distinct is heading each page with
        // the name of a recipe rather than a section -- Jerry Thomas does this.
        $distinctShare = $clusters === [] ? 0.0 : count($clusters) / $total;
        $headsAreRecipeNames = $distinctShare > 0.6;

        $bodyText = $this->bodyByPage($pages);
        $guideStems = [];

        foreach ($clusters as $cluster) {
            $pageNumbers = $cluster['pages'];
            $frequency = count($pageNumbers);
            $share = $frequency / $total;

            $stem = $this->guideWordStem($cluster['title']);

            if ($stem !== null) {
                // Fold "COCKTAIU" into "COCKTAILS" the same way head variants
                // fold, so one mis-scanned guide word does not become a second
                // section covering the same pages.
                $key = $this->stemKey($guideStems, $this->canonicalize($stem));

                $guideStems[$key]['title'] ??= $this->titleCase($stem);
                $guideStems[$key]['pages'] = array_merge($guideStems[$key]['pages'] ?? [], $pageNumbers);
                $guideStems[$key]['variants'] = ($guideStems[$key]['variants'] ?? []) + $cluster['variants'];

                $headByPage += $this->stripAll($cluster['variants']);

                continue;
            }

            if ($this->isBookTitle($cluster['key'], $bookKey, $share)) {
                $sections[] = new DetectedSection(
                    $cluster['title'],
                    SectionKind::RunningHead,
                    min($pageNumbers),
                    max($pageNumbers),
                    $cluster['variants'],
                    null,
                );

                $headByPage += $this->stripAll($cluster['variants']);

                continue;
            }

            if ($this->letterCount($cluster['title']) >= 4 && $this->isChapter($pageNumbers, $frequency)) {
                $from = min($pageNumbers);
                $to = max($pageNumbers);

                $sections[] = new DetectedSection(
                    $this->tidyTitle($cluster['title']),
                    SectionKind::Chapter,
                    $from,
                    $to,
                    $cluster['variants'],
                    $frequency / max(1, $to - $from + 1),
                );

                $headByPage += $this->stripAll($cluster['variants']);

                continue;
            }

            // A head that names a recipe is left in place unless the same name
            // is also printed in the body of that page, where it is genuinely a
            // duplicate. This is the weakest of the heuristics, so it is the one
            // that defaults to keeping the text.
            if ($headsAreRecipeNames) {
                foreach ($cluster['variants'] as $variant => $variantPages) {
                    foreach ($variantPages as $pageNumber) {
                        if ($this->repeatedInBody($variant, $bodyText[$pageNumber] ?? '')) {
                            $headByPage[$pageNumber] = $variant;
                        }
                    }
                }
            }
        }

        foreach ($guideStems as $stem) {
            $pageNumbers = $stem['pages'];
            sort($pageNumbers);

            $sections[] = new DetectedSection(
                $stem['title'],
                SectionKind::Section,
                min($pageNumbers),
                max($pageNumbers),
                $stem['variants'],
                count($pageNumbers) / max(1, max($pageNumbers) - min($pageNumbers) + 1),
            );
        }

        usort($sections, fn (DetectedSection $a, DetectedSection $b): int => [$a->pageFrom, $a->pageTo] <=> [$b->pageFrom, $b->pageTo]);

        $sections = $this->mergeSameTitle($sections);

        $named = array_filter($sections, fn (DetectedSection $s): bool => $s->kind->carriesText());

        if ($named === []) {
            // Nothing was detected. One untitled section over the whole book is
            // the honest answer; the Python original title-cased whatever caps
            // line it found last, which produced names such as "Cocktails Bl-Bo".
            $sections[] = new DetectedSection(
                null,
                SectionKind::Body,
                (int) $pages->min('page_number'),
                (int) $pages->max('page_number'),
            );
        }

        return [array_values($sections), $headByPage];
    }

    /**
     * Fold together sections that name the same thing over the same pages.
     *
     * Cafe Royal heads some pages "COCKTAILS" and most of them "COCKTAILS
     * BL-BO", which arrive here as a chapter and a guide-word section with the
     * same name and almost the same span. Two sections called Cocktails would
     * put two different titles on neighbouring chunks of one list.
     *
     * @param  list<DetectedSection>  $sections
     * @return list<DetectedSection>
     */
    private function mergeSameTitle(array $sections): array
    {
        $merged = [];

        foreach ($sections as $section) {
            $target = null;

            foreach ($merged as $index => $existing) {
                if ($existing->kind !== $section->kind && ! $existing->kind->carriesText()) {
                    continue;
                }

                $sameName = $existing->title !== null
                    && $section->title !== null
                    && $this->withinEditBudget(
                        $this->canonicalize($existing->title),
                        $this->canonicalize($section->title),
                    );

                // Overlapping, or close enough that a gap between them is a
                // plate rather than a different part of the book.
                $overlaps = $section->pageFrom <= $existing->pageTo + 3
                    && $existing->pageFrom <= $section->pageTo + 3;

                if ($sameName && $overlaps) {
                    $target = $index;

                    break;
                }
            }

            if ($target === null) {
                $merged[] = $section;

                continue;
            }

            $existing = $merged[$target];
            $existing->pageFrom = min($existing->pageFrom, $section->pageFrom);
            $existing->pageTo = max($existing->pageTo, $section->pageTo);
            $existing->headVariants += $section->headVariants;

            $pages = 0;

            foreach ($existing->headVariants as $variantPages) {
                $pages += count($variantPages);
            }

            $existing->confidence = $pages / max(1, $existing->pageTo - $existing->pageFrom + 1);
        }

        return array_values($merged);
    }

    /**
     * Whether a cluster is the book's own title running along the page edge.
     *
     * Two signals, either of which is enough: it reads like the title, or it
     * appears across a large share of the whole book. Old Waldorf Bar Days
     * carries its title on 103 of 253 pages and trips both.
     *
     * Deliberately *not* a signal: which side of the spine the head falls on.
     * A verso title does sit on one side -- but so does every recto chapter
     * head in these books, so testing parity classified "Hall of Fame",
     * "Cocktails" and "Faculty and Proctors" as noise. The share test already
     * catches a real title without taking the chapters with it.
     */
    private function isBookTitle(string $key, string $bookKey, float $share): bool
    {
        if ($bookKey !== '' && $key !== '') {
            similar_text($key, $bookKey, $percent);

            if ($percent >= 80.0) {
                return true;
            }
        }

        return $share >= 0.35;
    }

    /**
     * Whether a cluster's pages form a chapter-shaped run.
     *
     * Density rather than a maximum gap, because these books head only the
     * recto, so the natural gap between appearances is already two pages and a
     * single plate or unheaded opener pushes it to four or more. A strict gap
     * rule dropped Old Waldorf's "Cocktails" chapter, which heads 25 pages
     * across pages 127 to 185. Density keeps it while still rejecting a head
     * that recurs in two unrelated parts of a book.
     *
     * @param  list<int>  $pageNumbers
     */
    private function isChapter(array $pageNumbers, int $frequency): bool
    {
        if ($frequency < 3) {
            return false;
        }

        $span = max($pageNumbers) - min($pageNumbers) + 1;

        return $span >= 3 && ($frequency / $span) >= 0.25;
    }

    /**
     * The stem of a guide word such as "COCKTAILS BL-BO".
     *
     * Cafe Royal heads every page with the alphabetical range of the drinks on
     * it, which yields 169 distinct heads across 265 pages. Grouping them by
     * stem turns that into a handful of real sections instead of 169 junk ones.
     */
    private function guideWordStem(string $head): ?string
    {
        $pattern = '/^(?<stem>.*?\p{L}.*?)\s+\p{Lu}{1,3}\s*[-\x{2013}\x{2014}]\s*\p{Lu}{1,3}\.?$/u';

        if (preg_match($pattern, trim($head), $matches) !== 1) {
            return null;
        }

        $stem = trim($matches['stem']);

        return $this->letterCount($stem) >= 3 ? $stem : null;
    }

    /**
     * The guide-word stem an OCR variant belongs to, creating it if new.
     *
     * @param  array<string, array{title?: string, pages?: list<int>, variants?: array<string, list<int>>}>  $stems
     */
    private function stemKey(array $stems, string $key): string
    {
        foreach (array_keys($stems) as $existing) {
            if ($this->withinEditBudget($key, (string) $existing)) {
                return (string) $existing;
            }
        }

        return $key;
    }

    /**
     * Every page a cluster's spellings appeared on, mapped to the exact line.
     *
     * @param  array<string, list<int>>  $variants
     * @return array<int, string>
     */
    private function stripAll(array $variants): array
    {
        $stripped = [];

        foreach ($variants as $variant => $pageNumbers) {
            foreach ($pageNumbers as $pageNumber) {
                $stripped[$pageNumber] = (string) $variant;
            }
        }

        return $stripped;
    }

    /**
     * Each page's text with its edge lines removed, for the duplicate test.
     *
     * @param  Collection<int, BookPage>  $pages
     * @return array<int, string>
     */
    private function bodyByPage(Collection $pages): array
    {
        $bodies = [];

        foreach ($pages as $page) {
            $lines = array_values(array_filter(
                explode("\n", (string) $page->text),
                fn (string $line): bool => trim($line) !== '',
            ));

            $bodies[(int) $page->page_number] = implode("\n", array_slice($lines, 1, max(0, count($lines) - 2)));
        }

        return $bodies;
    }

    /**
     * Whether a head is also printed inside the page it heads.
     */
    private function repeatedInBody(string $head, string $body): bool
    {
        $needle = $this->canonicalize($head);

        if ($needle === '' || mb_strlen($needle) < 4) {
            return false;
        }

        return str_contains($this->canonicalize($body), $needle);
    }

    /**
     * Turn a shouted head into a readable title, without inventing anything.
     */
    private function tidyTitle(string $head): string
    {
        $head = $this->patterns->trimEdges($head);

        return $this->isAllCaps($head) ? $this->titleCase($head) : $head;
    }

    private function titleCase(string $text): string
    {
        return mb_convert_case(mb_strtolower(trim($text)), MB_CASE_TITLE, 'UTF-8');
    }

    private function isAllCaps(string $text): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $text) ?? '';

        if ($letters === '') {
            return false;
        }

        return $letters === mb_strtoupper($letters);
    }

    private function withinEditBudget(string $a, string $b): bool
    {
        // levenshtein() works on bytes and refuses long arguments, and these
        // keys are short by construction.
        $a = substr($a, 0, 240);
        $b = substr($b, 0, 240);

        if ($a === $b) {
            return true;
        }

        // Roughly one edit per six characters. A tighter budget left
        // "COCKTAIU" stranded from "COCKTAILS", which is two edits on nine
        // characters, and duplicated a whole section of Cafe Royal.
        $budget = max(1, (int) round(max(strlen($a), strlen($b)) / 6.0));

        return levenshtein($a, $b) <= $budget;
    }

    /**
     * Uppercase, letters and digits only, so that punctuation and spacing
     * differences do not split a head from itself.
     */
    private function canonicalize(string $head): string
    {
        $upper = mb_strtoupper($head);

        return preg_replace('/[^\p{L}\p{N}]/u', '', $upper) ?? '';
    }

    private function letterCount(string $text): int
    {
        return mb_strlen(preg_replace('/[^\p{L}]/u', '', $text) ?? '');
    }
}
