<?php

namespace BaseOS\Connect\Data;

/**
 * Who is calling: the verified connection, the resolved tenant (website) and the protocol in use.
 */
final class BridgeContext
{
    public function __construct(
        public readonly BridgeConnection $connection,
        public readonly ?string $tenant = null,
        public readonly string $protocol = '2.1',
        public readonly ?string $commandId = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public function connectionId(): string
    {
        return $this->connection->connectionId;
    }

    public function supports(string $version): bool
    {
        return version_compare($this->protocol, $version, '>=');
    }
}
