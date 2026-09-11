<?php

namespace App\Enums;

enum SectionKind: string
{
    /**
     * The whole book, used when no structure could be detected. A book with one
     * Body section is a book whose chunks cite pages but no chapter.
     */
    case Body = 'body';

    /**
     * A named chapter, recognised from a running head that repeats over a
     * contiguous run of pages.
     */
    case Chapter = 'chapter';

    /**
     * A division below a chapter, or one recognised from an in-page heading
     * such as "131. TODDIES AND SLINGS".
     */
    case Section = 'section';

    /**
     * A repeated head that carries no structure, almost always the book's own
     * title on the verso. Recorded rather than merely dropped, so that every
     * string removed from chunk text has a receipt.
     */
    case RunningHead = 'running_head';

    case FrontMatter = 'front_matter';
    case BackMatter = 'back_matter';
    case Index = 'index';

    public function label(): string
    {
        return match ($this) {
            self::Body => 'Body',
            self::Chapter => 'Chapter',
            self::Section => 'Section',
            self::RunningHead => 'Running head',
            self::FrontMatter => 'Front matter',
            self::BackMatter => 'Back matter',
            self::Index => 'Index',
        };
    }

    /**
     * Whether this section contributes text to the chunk stream.
     */
    public function carriesText(): bool
    {
        return $this !== self::RunningHead;
    }
}
