<?php

namespace BaseOS\Connect\Connections;

use BaseOS\Connect\Contracts\ConnectionStore;
use BaseOS\Connect\Data\BridgeConnection;
use LogicException;

/**
 * Single-site, read-only: BASEOS_CONNECTION_ID / BASEOS_SECRET (+ BASEOS_TENANT) from env.
 */
final class ConfigConnectionStore implements ConnectionStore
{
    /** @param  array<string, mixed>  $config */
    public function __construct(private array $config) {}

    public function find(string $connectionId): ?BridgeConnection
    {
        $c = $this->connection();

        return $c && hash_equals($c->connectionId, $connectionId) ? $c : null;
    }

    public function all(): array
    {
        return array_values(array_filter([$this->connection()]));
    }

    public function save(BridgeConnection $connection): void
    {
        throw new LogicException('Connection driver "config" is read-only. Put BASEOS_CONNECTION_ID / BASEOS_SECRET in .env.');
    }

    public function delete(string $connectionId): void
    {
        throw new LogicException('Connection driver "config" is read-only.');
    }

    public function writable(): bool
    {
        return false;
    }

    private function connection(): ?BridgeConnection
    {
        $id = (string) ($this->config['connection_id'] ?? '');
        $secret = (string) ($this->config['secret'] ?? '');
        if ($id === '' || $secret === '') {
            return null;
        }

        return new BridgeConnection(
            connectionId: $id,
            secret: $secret,
            tenant: ($this->config['tenant'] ?? null) ?: null,
            siteUrl: ($this->config['site_url'] ?? null) ?: null,
            receiverUrl: ($this->config['receiver_url'] ?? null) ?: null,
            protocol: (string) (($this->config['protocol'] ?? null) ?: '2.1'),
        );
    }
}
