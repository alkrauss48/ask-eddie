<?php

namespace App\Services\Retrieval;

/**
 * What a guest wants counted.
 *
 * Every field is optional, because "what comes up time and time again?" has to
 * be answerable with no arguments at all.
 */
readonly class DrinkQuery
{
    public const ORDERS = ['books', 'mentions', 'earliest', 'latest'];

    public function __construct(
        public ?string $name = null,
        public string $order = 'books',
        public int $limit = 10,
        public ?int $fromYear = null,
        public ?int $toYear = null,
        public ?int $minBooks = null,
    ) {}
}
