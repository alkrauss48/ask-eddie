<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();

            // Identity. The slug is derived once at import and is deliberately
            // independent of the filename, so renaming a PDF never orphans the
            // chunks and citations that will reference this book later.
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('author')->nullable();
            $table->smallInteger('year')->nullable();

            // Tesseract language code(s), e.g. "eng" or "spa+eng".
            $table->string('language')->default('eng');

            // File state. Size and modified time provide a fast path so that
            // re-importing does not re-hash 1.8 GB of PDFs on every run.
            $table->string('source_filename')->unique();
            $table->string('checksum', 64);
            $table->unsignedBigInteger('file_size');
            $table->timestamp('file_modified_at')->nullable();
            $table->unsignedInteger('page_count')->nullable();

            $table->string('status')->default('pending');
            $table->string('preferred_text_source')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
