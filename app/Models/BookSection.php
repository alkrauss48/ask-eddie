<?php

namespace App\Models;

use App\Enums\SectionKind;
use Database\Factories\BookSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookSection extends Model
{
    /** @use HasFactory<BookSectionFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'kind' => SectionKind::class,
            'page_from' => 'integer',
            'page_to' => 'integer',
            'head_variants' => 'array',
            'confidence' => 'float',
            'detector_version' => 'integer',
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
     * @return HasMany<BookChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(BookChunk::class)->orderBy('chunk_index');
    }

    /**
     * The section's page span, as it would read in a citation.
     */
    public function pageRangeLabel(): string
    {
        return $this->page_from === $this->page_to
            ? "p{$this->page_from}"
            : "pp{$this->page_from}-{$this->page_to}";
    }
}
