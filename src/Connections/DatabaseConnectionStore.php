<?php

namespace BaseOS\Connect\Connections;

use BaseOS\Connect\Contracts\ConnectionStore;
use BaseOS\Connect\Data\BridgeConnection;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;

final class DatabaseConnectionStore implements ConnectionStore
{
    public function __construct(
        private ConnectionResolverInterface $db,
        private Encrypter $crypt,
        private string $table = 'baseos_connect_connections',
        private ?string $dbConnection = null,
    ) {}

    public function find(string $connectionId): ?BridgeConnection
    {
        $row = $this->query()->where('connection_id', $connectionId)->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function all(): array
    {
        return array_values(array_filter(array_map(
            fn ($row) => $this->hydrate($row, false),
            $this->query()->orderBy('id')->get()->all(),
        )));
    }

    public function save(BridgeConnection $connection): void
    {
        $now = now();
        $values = [
            'secret' => $this->crypt->encryptString($connection->secret()),
            'tenant' => $connection->tenant,
            'site_url' => $connection->siteUrl,
            'receiver_url' => $connection->receiverUrl,
            'protocol' => $connection->protocol,
            'meta' => json_encode($connection->meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];

        $exists = $this->query()->where('connection_id', $connection->connectionId)->exists();
        if ($exists) {
            $this->query()->where('connection_id', $connection->connectionId)->update($values);
        } else {
            $this->query()->insert($values + ['connection_id' => $connection->connectionId, 'created_at' => $now]);
        }
    }

    public function delete(string $connectionId): void
    {
        $this->query()->where('connection_id', $connectionId)->delete();
    }

    public function writable(): bool
    {
        return true;
    }

    private function query(): Builder
    {
        return $this->db->connection($this->dbConnection)->table($this->table);
    }

    private function hydrate(object $row, bool $strict = true): ?BridgeConnection
    {
        try {
            $secret = $this->crypt->decryptString((string) $row->secret);
        } catch (\Throwable $e) {
            if ($strict) {
                return null;
            }
            $secret = '';
        }

        $meta = is_string($row->meta ?? null) ? json_decode($row->meta, true) : ($row->meta ?? []);

        return new BridgeConnection(
            connectionId: (string) $row->connection_id,
            secret: $secret,
            tenant: $row->tenant !== null && $row->tenant !== '' ? (string) $row->tenant : null,
            siteUrl: $row->site_url ?? null,
            receiverUrl: $row->receiver_url ?? null,
            protocol: (string) ($row->protocol ?: '2.1'),
            meta: is_array($meta) ? $meta : [],
        );
    }
}
