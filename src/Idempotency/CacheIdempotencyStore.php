<?php

namespace BaseOS\Connect\Idempotency;

use BaseOS\Connect\Contracts\IdempotencyStore;
use Illuminate\Contracts\Cache\Repository;

final class CacheIdempotencyStore implements IdempotencyStore
{
    public function __construct(private Repository $cache, private string $prefix = 'baseos-connect:idem:') {}

    public function get(string $key): ?array
    {
        $v = $this->cache->get($this->prefix.$key);

        return is_array($v) ? $v : null;
    }

    public function put(string $key, array $response, int $ttlSeconds): void
    {
        $this->cache->put($this->prefix.$key, $response, $ttlSeconds);
    }

    public function prune(): int
    {
        return 0;
    }
}
