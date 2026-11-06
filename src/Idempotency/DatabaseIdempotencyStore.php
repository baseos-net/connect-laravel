<?php

namespace BaseOS\Connect\Idempotency;

use BaseOS\Connect\Contracts\IdempotencyStore;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

final class DatabaseIdempotencyStore implements IdempotencyStore
{
    public function __construct(
        private ConnectionResolverInterface $db,
        private string $table = 'baseos_connect_idempotency',
        private ?string $dbConnection = null,
    ) {}

    public function get(string $key): ?array
    {
        $row = $this->query()->where('key', $key)->where('expires_at', '>', now())->first();
        if (! $row) {
            return null;
        }
        $decoded = json_decode((string) $row->response, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function put(string $key, array $response, int $ttlSeconds): void
    {
        $now = now();
        $values = [
            'response' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'expires_at' => $now->copy()->addSeconds($ttlSeconds),
            'updated_at' => $now,
        ];

        try {
            if ($this->query()->where('key', $key)->exists()) {
                $this->query()->where('key', $key)->update($values);
            } else {
                $this->query()->insert($values + ['key' => $key, 'created_at' => $now]);
            }
        } catch (UniqueConstraintViolationException) {
            // Concurrent duplicate delivery stored the same result first.
        }
    }

    public function prune(): int
    {
        return $this->query()->where('expires_at', '<=', now())->delete();
    }

    private function query(): Builder
    {
        return $this->db->connection($this->dbConnection)->table($this->table);
    }
}
