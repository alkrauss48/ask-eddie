<?php

namespace App\Models;

use App\Enums\PageStatus;
use App\Enums\PageTextSource;
use Database\Factories\BookPageExtractionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookPageExtraction extends Model
{
    /** @use HasFactory<BookPageExtractionFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'text_source' => PageTextSource::class,
            'quality_score' => 'float',
            'score_breakdown' => 'array',
            'settings' => 'array',
            'normalizer_version' => 'integer',
            'duration_ms' => 'integer',
            'status' => PageStatus::class,
        ];
    }

    /**
     * @return BelongsTo<BookPage, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(BookPage::class, 'book_page_id');
    }
}
