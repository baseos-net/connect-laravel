<?php

namespace BaseOS\Connect\Contracts;

use BaseOS\Connect\Data\BridgeConnection;

interface ConnectionStore
{
    public function find(string $connectionId): ?BridgeConnection;

    /** @return list<BridgeConnection> */
    public function all(): array;

    public function save(BridgeConnection $connection): void;

    public function delete(string $connectionId): void;

    public function writable(): bool;
}
