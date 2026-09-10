<?php

namespace App\Models;

use App\Enums\BookStatus;
use App\Enums\PageTextSource;
use Database\Factories\BookFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Book extends Model
{
    /** @use HasFactory<BookFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'file_size' => 'integer',
            'file_modified_at' => 'datetime',
            'page_count' => 'integer',
            'status' => BookStatus::class,
            'preferred_text_source' => PageTextSource::class,
            'metadata' => 'array',
            'extracted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BookPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(BookPage::class)->orderBy('page_number');
    }

    /**
     * @return HasManyThrough<BookPageExtraction, BookPage, $this>
     */
    public function extractions(): HasManyThrough
    {
        return $this->hasManyThrough(BookPageExtraction::class, BookPage::class);
    }

    /**
     * The absolute path of the source PDF on the configured books disk.
     */
    public function sourcePath(): string
    {
        return rtrim(config('filesystems.disks.'.config('books.disk').'.root'), '/')
            .'/'.$this->source_filename;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
