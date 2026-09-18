<?php

namespace App\Services\Retrieval;

use Illuminate\Contracts\Support\Arrayable;

/**
 * A passage a hybrid retriever can find, whichever corpus it came from.
 *
 * Two things are promised here and nothing else. toArray() is the payload the
 * language model is handed -- both implementations assert it by key count, for
 * the reason .ai/rules/retrieval.md gives -- and embeddingText() is the
 * provenance-prefixed string the vector was built from, which is what a
 * reranker must score so that the cross-encoder and the embedder are looking at
 * one passage rather than two renderings of it.
 *
 * Deliberately not an abstract base class. The difference between a book
 * passage and a house passage is entirely data -- a page range against a URL --
 * and a base class with a protected hook would make the retriever's own query
 * body overridable, which is precisely the thing that must not vary: the moment
 * a subclass can rewrite dense(), it can drop whereNotNull('embedding') and
 * nothing will notice.
 *
 * @extends Arrayable<string, mixed>
 */
interface RetrievablePassage extends Arrayable
{
    /**
     * The string this passage's vector was built from, provenance and all.
     */
    public function embeddingText(): string;
}
