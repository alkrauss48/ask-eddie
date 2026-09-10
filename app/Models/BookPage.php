<?php

namespace App\Models;

use App\Enums\PageStatus;
use App\Enums\PageTextSource;
use Database\Factories\BookPageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookPage extends Model
{
    /** @use HasFactory<BookPageFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'page_number' => 'integer',
            'char_count' => 'integer',
            'word_count' => 'integer',
            'status' => PageStatus::class,
            'text_source' => PageTextSource::class,
        ];
    }

    /**
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * @return HasMany<BookPageExtraction, $this>
     */
    public function extractions(): HasMany
    {
        return $this->hasMany(BookPageExtraction::class);
    }

    /**
     * The extraction of the given source, if one has been recorded.
     */
    public function extractionFrom(PageTextSource $source): ?BookPageExtraction
    {
        return $this->extractions->firstWhere('text_source', $source);
    }
}
