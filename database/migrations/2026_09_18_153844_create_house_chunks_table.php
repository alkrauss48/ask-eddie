<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The retrievable half of the house corpus: one chunk per record.
     *
     * A separate table from book_chunks rather than a shared one, and not only
     * for tidiness. `books:embed --verify` asserts exactly one
     * (model, dimensions, version) triple *table-wide* over book_chunks; a
     * shared table would stretch that invariant across two corpora with two
     * independent renderer versions, and the first house render change would
     * fail Eddie's verifier. Separate tables leave every existing invariant
     * untouched.
     *
     * Nothing here mirrors the book pipeline's byte arithmetic. There is no OCR,
     * no page stream, no packer and no segmenter: every record is far under the
     * render ceiling, so a cocktail is exactly one chunk and its provenance is a
     * slug rather than an offset.
     */
    public function up(): void
    {
        // The extension is not pre-created by an init script, so a fresh volume
        // -- or the separate testing database -- starts without it.
        Schema::ensureVectorExtensionExists();

        Schema::create('house_chunks', function (Blueprint $table) {
            $table->id();

            // cocktail | recipe | bartender | menu | path. Not a morph: the
            // rows these point at live in five different tables and the id is
            // recorded for convenience, while the pair below is the identity.
            //
            // "ingredient" is deliberately absent. A rendered ingredient is
            // "Smith and Cross. Jamaican Rum, a base spirit." -- near-zero
            // prose, and 33 of the 131 are mutually near-identical under
            // bge-m3, so embedding them means a query for "rum" returns thirty
            // nearly-tied ingredient chunks that crowd out every actual
            // cocktail. They also have no URL to cite. Every ingredient title
            // still reaches retrieval as a B-weighted keyword on the cocktails
            // that use it, which is the only context a guest ever asks about
            // one in.
            $table->string('source_type');
            $table->string('source_slug');
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('title');
            $table->string('subtitle')->nullable();

            // The rendered passage. NOT NULL, which is why it is the one part
            // of the generated column below that is not wrapped in coalesce.
            $table->text('text');

            // The structured vocabulary, flattened: "Smith and Cross",
            // "Jamaican Rum", "Higher Alcohol", "Tiki", "Summer Menu", "Trader
            // Vic". This has no book_chunks analogue and it is what makes the
            // catalog findable by a sentence -- "something smoky and boozy"
            // must hit the tag vocabulary harder than a passing prose mention
            // of smoke, which is what the B weight below buys.
            $table->json('keywords')->nullable();

            // The page on the site this passage came off, absolute. A house
            // citation is a link a guest can open, which is the whole of its
            // provenance -- there is no page number to check it against.
            $table->string('url');

            // sha256 of the exact string that gets embedded, prefix and all --
            // not of "text" alone. The prefix carries the title and the
            // collection a drink is on, so a retitled cocktail whose body never
            // moved must still be re-embedded, and a hash over the body could
            // not say so.
            $table->string('content_hash', 64);

            $table->unsignedSmallInteger('renderer_version')->default(1);

            $table->unsignedInteger('char_count');
            $table->unsignedInteger('word_count');
            $table->unsignedInteger('token_estimate');

            // Every rendered record is indexable today. The column is here
            // because the pending predicate and the invariants below are
            // written in terms of it, so excluding something later is an update
            // rather than a schema change -- the same standing it has on
            // book_chunks, where nothing is ever deleted for being unindexable.
            $table->boolean('is_indexable')->default(true);

            // 1024 is bge-m3's native width, hard-coded rather than read from
            // config('house.embedding.dimensions') because a migration must
            // replay identically forever. HouseChunkEmbeddingSchemaTest pins
            // the two together instead.
            $table->vector('embedding', 1024)->nullable();

            $table->string('embedding_model')->nullable();
            $table->unsignedSmallInteger('embedding_dimensions')->nullable();
            $table->unsignedSmallInteger('embedder_version')->nullable();
            $table->timestamp('embedded_at')->nullable();

            // The content_hash as it stood when the vector was made. Books
            // detects a moved passage as embedded_at < updated_at, which
            // --verify reports as a failure an operator has to act on; storing
            // the hash instead means a changed render is simply *pending*, and
            // the next run fixes it. That is what makes bumping
            // HouseRenderer::VERSION cheap enough to actually do.
            $table->string('embedded_content_hash', 64)->nullable();

            $table->timestamps();

            // One chunk per record, and the key an import upserts against.
            $table->unique(['source_type', 'source_slug']);
            $table->index(['source_type', 'source_id']);
        });

        // Hand-written for the same reason book_chunks' is: PostgresGrammar's
        // compileFulltext() emits to_tsvector(cfg, a) || to_tsvector(cfg, b)
        // with no coalesce, and NULL propagates through ||. Most chunks here
        // have no subtitle and a menu has no keywords worth the name, so
        // fullText() would index a good share of this corpus as NULL -- no
        // error, no match, ever.
        //
        // The weights are the ranking decision. The title is what a guest names
        // a drink by; the keyword vocabulary sits above the body because a tag
        // that says "Tiki" is a stronger claim than a sentence that happens to
        // mention tiki. subtitle is deliberately absent: HouseRenderer already
        // puts it in "text", and indexing it twice would double-weight a
        // marketing line.
        //
        // keywords #>> '{}' resolves to json_extract_path_text, which is
        // immutable and therefore legal in a generated column -- the same trick
        // book_chunks uses for headings.
        DB::statement(<<<'SQL'
            alter table house_chunks
                add column search_vector tsvector
                generated always as (
                    setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                    setweight(to_tsvector('english', coalesce(keywords #>> '{}', '')), 'B') ||
                    setweight(to_tsvector('english', text), 'C')
                ) stored
        SQL);

        DB::statement('create index house_chunks_search_vector_gin on house_chunks using gin (search_vector)');

        // What house:embed asks for on every batch.
        DB::statement('create index house_chunks_pending_embedding on house_chunks (id)
            where is_indexable and embedding is null');

        /*
         * There is deliberately no HNSW index here, and its absence is asserted
         * by HouseChunkEmbeddingSchemaTest so that adding one later is a
         * decision rather than an accident.
         *
         * At roughly 207 rows a 1024-wide exact scan is about 850 KB and
         * sub-millisecond, with 100% recall. Approximate search buys nothing
         * measurable at this size and costs real recall risk: pgvector
         * post-filters, so an is_indexable predicate over an approximate scan
         * can quietly return a short list -- the exact failure
         * books.retrieval.ef_search exists to prevent, arriving through a
         * different door. This is also why config/house.php has no ef_search
         * key: a knob wired to nothing is worse than no knob.
         */
    }

    public function down(): void
    {
        DB::statement('drop index if exists house_chunks_pending_embedding');
        DB::statement('drop index if exists house_chunks_search_vector_gin');

        Schema::dropIfExists('house_chunks');
    }
};
