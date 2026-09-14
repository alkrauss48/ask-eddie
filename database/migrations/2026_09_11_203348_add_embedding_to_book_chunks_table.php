<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The extension is not pre-created by an init script, so a fresh volume
        // -- or the separate testing database -- starts without it.
        Schema::ensureVectorExtensionExists();

        Schema::table('book_chunks', function (Blueprint $table): void {
            // 1024 is bge-m3's native width and is hard-coded rather than read
            // from config('books.embedding.dimensions'), because a migration
            // must replay identically forever. The two are pinned together by
            // BookChunkEmbeddingSchemaTest instead, so a divergence fails a
            // test at edit time rather than a query at retrieval time.
            $table->vector('embedding', 1024)->nullable();

            // Which model produced the vector, at what width, through which
            // version of the string we hand it. books:embed treats a row whose
            // triple no longer matches configuration as pending, so trying a
            // new model is a command rather than a manual re-run.
            //
            // Deliberately a plain string, not an enum: an enum would need a
            // migration to try a model, and trying models is the point.
            $table->string('embedding_model')->nullable();
            $table->unsignedSmallInteger('embedding_dimensions')->nullable();
            $table->unsignedSmallInteger('embedder_version')->nullable();
            $table->timestamp('embedded_at')->nullable();

            // The lexical half of hybrid retrieval, stored rather than computed
            // per query.
            //
            // This is written by hand instead of with $table->fullText(),
            // which would silently exclude 64% of the corpus:
            // PostgresGrammar::compileFulltext() emits
            // to_tsvector(cfg, a) || to_tsvector(cfg, b) with no coalesce, and
            // NULL propagates through ||. Only 56% of chunks have a heading and
            // 36% a section_title, so every chunk missing either would index as
            // NULL -- no error, no match, ever.
            //
            // 'english' is baked in here for the whole corpus. Non-English is
            // 951 of 24,926 chunks (3.8%); the English stemmer under-stems them
            // but never drops them, and an exact drink-name token still
            // matches. Changing it means another migration.
            //
            // headings is included because a packed recipe-list chunk names
            // every drink it holds, not just the one it opens with. '#>>' on a
            // json column resolves to json_extract_path_text, which is
            // immutable and therefore legal in a generated column.
            $table->tsvector('search_vector')->storedAs(DB::raw(<<<'SQL'
                setweight(to_tsvector('english', coalesce(heading, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(headings #>> '{}', '')), 'A') ||
                setweight(to_tsvector('english', coalesce(section_title, '')), 'B') ||
                setweight(to_tsvector('english', text), 'C')
            SQL));
        });

        DB::statement('create index book_chunks_search_vector_gin on book_chunks using gin (search_vector)');

        // vectorIndex() cannot carry HNSW parameters -- compileVectorIndex()
        // emits no "with (...)" clause -- so the index is raw. m = 16 is
        // pgvector's default and right at this scale; ef_construction = 128
        // costs seconds on a one-time build over 25k rows.
        //
        // vector_cosine_ops is a one-way door: whereVectorSimilarTo() compiles
        // to <=> and converts minSimilarity as 1 - similarity, which is only
        // meaningful for cosine. An l2_ops index would rank *almost* right,
        // which is worse than ranking wrong.
        DB::statement('create index book_chunks_embedding_hnsw on book_chunks
            using hnsw (embedding vector_cosine_ops) with (m = 16, ef_construction = 128)');

        // What books:embed asks for on every batch.
        DB::statement('create index book_chunks_pending_embedding on book_chunks (id)
            where is_indexable and embedding is null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists book_chunks_pending_embedding');
        DB::statement('drop index if exists book_chunks_embedding_hnsw');
        DB::statement('drop index if exists book_chunks_search_vector_gin');

        Schema::table('book_chunks', function (Blueprint $table): void {
            $table->dropColumn([
                'embedding',
                'embedding_model',
                'embedding_dimensions',
                'embedder_version',
                'embedded_at',
                'search_vector',
            ]);
        });
    }
};
