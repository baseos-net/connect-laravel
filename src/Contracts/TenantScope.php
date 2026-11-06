<?php

namespace BaseOS\Connect\Contracts;

/**
 * Switches your app into a tenant before a command runs (set current site, global scopes,
 * DB connection, cache prefix…) and restores it afterwards. leave() always runs (finally).
 */
interface TenantScope
{
    public function enter(string $tenant): void;

    public function leave(): void;
}
