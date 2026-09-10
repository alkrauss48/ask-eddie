<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_page_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_page_id')->constrained()->cascadeOnDelete();

            // One row per (page, source). Keeping both the text layer and the
            // OCR result side by side means deciding which one wins is a cheap,
            // reversible query rather than hours of re-OCR.
            $table->string('text_source');

            // Exactly what the tool emitted, kept forever so the normalizer can
            // be improved and re-run without touching the PDFs again.
            $table->text('raw_text')->nullable();
            $table->text('text')->nullable();

            $table->float('quality_score')->nullable();
            $table->json('score_breakdown')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('normalizer_version')->default(1);
            $table->unsignedInteger('duration_ms')->nullable();

            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['book_page_id', 'text_source']);
            $table->index(['book_page_id', 'quality_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_page_extractions');
    }
};
