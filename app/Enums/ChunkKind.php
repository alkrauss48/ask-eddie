<?php

namespace App\Enums;

enum ChunkKind: string
{
    /**
     * A single drink recipe: a heading followed by measures or instructions.
     */
    case Recipe = 'recipe';

    /**
     * Several whole recipes packed together, which is the usual shape for the
     * books that are nothing but drink lists.
     */
    case RecipeList = 'recipe_list';

    /**
     * Running text. The narrative books are mostly this.
     */
    case Prose = 'prose';

    case Index = 'index';
    case TableOfContents = 'table_of_contents';

    /**
     * A publisher's advertisement for unrelated books, bound into the back of
     * several of these volumes.
     */
    case Advertisement = 'advertisement';

    case FrontMatter = 'front_matter';
    case BackMatter = 'back_matter';

    /**
     * Scanner artefacts and stray marks, such as "3%" or ".~ °°".
     */
    case Noise = 'noise';

    /**
     * Nothing matched. Deliberately retrievable: the Python original this
     * pipeline replaces discarded anything it could not vouch for, which cost
     * it real recipes. Failing open costs a little noise instead.
     */
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Recipe => 'Recipe',
            self::RecipeList => 'Recipe list',
            self::Prose => 'Prose',
            self::Index => 'Index',
            self::TableOfContents => 'Contents',
            self::Advertisement => 'Advertisement',
            self::FrontMatter => 'Front matter',
            self::BackMatter => 'Back matter',
            self::Noise => 'Noise',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * Whether chunks of this kind should reach the vector index.
     *
     * Nothing is ever deleted for failing this test; it only decides what gets
     * embedded, so reversing a judgement is an update rather than a re-run.
     */
    public function isIndexable(): bool
    {
        return match ($this) {
            self::Recipe, self::RecipeList, self::Prose, self::Unknown => true,
            default => false,
        };
    }
}
