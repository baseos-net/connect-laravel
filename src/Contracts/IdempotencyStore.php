<?php

namespace BaseOS\Connect\Contracts;

interface IdempotencyStore
{
    /** @return array<string, mixed>|null */
    public function get(string $key): ?array;

    /** @param  array<string, mixed>  $response */
    public function put(string $key, array $response, int $ttlSeconds): void;

    /** Delete expired rows; returns the number removed. */
    public function prune(): int;
}
