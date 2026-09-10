<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();

            // Physical position in the PDF, 1-based.
            $table->unsignedInteger('page_number');

            // The page number as printed on the page itself, which is usually
            // offset from the physical page by the front matter. Captured
            // before normalization strips it, because citations need it.
            $table->string('printed_page_label')->nullable();

            // The promoted result: whichever extraction won for this page.
            $table->text('text')->nullable();
            $table->string('text_source')->nullable();
            $table->unsignedBigInteger('promoted_extraction_id')->nullable();
            $table->unsignedInteger('char_count')->default(0);
            $table->unsignedInteger('word_count')->default(0);

            $table->string('status')->default('pending');
            $table->timestamps();

            $table->unique(['book_id', 'page_number']);
            $table->index(['book_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_pages');
    }
};
