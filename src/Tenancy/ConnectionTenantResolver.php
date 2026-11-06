<?php

namespace BaseOS\Connect\Tenancy;

use BaseOS\Connect\Contracts\TenantResolver;
use BaseOS\Connect\Data\BridgeConnection;

/**
 * Default resolver:
 *   1. tenant stored on the connection (baseos:pair --tenant=…)
 *   2. config tenancy.hosts[site host]  (www. ignored)
 *   3. the site host itself when tenancy.from_host = true
 *   4. null (single site)
 */
final class ConnectionTenantResolver implements TenantResolver
{
    /**
     * @param  array<string, string>  $hosts
     */
    public function __construct(private array $hosts = [], private bool $fromHost = false) {}

    public function resolve(BridgeConnection $connection): ?string
    {
        if ($connection->tenant !== null && $connection->tenant !== '') {
            return $connection->tenant;
        }

        $host = $connection->siteHost();
        if ($host === null) {
            return null;
        }

        foreach ($this->hosts as $mapHost => $tenant) {
            $norm = strtolower(preg_replace('/^www\./i', '', (string) $mapHost) ?? (string) $mapHost);
            if ($norm === $host) {
                return (string) $tenant;
            }
        }

        return $this->fromHost ? $host : null;
    }
}
