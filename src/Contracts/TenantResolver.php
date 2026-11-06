<?php

namespace BaseOS\Connect\Contracts;

use BaseOS\Connect\Data\BridgeConnection;

/**
 * Decides which website (tenant) a connection points to. Base OS never sends a tenant —
 * the receiver derives it from the connection identity.
 */
interface TenantResolver
{
    public function resolve(BridgeConnection $connection): ?string;
}
