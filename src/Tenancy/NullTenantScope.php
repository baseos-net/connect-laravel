<?php

namespace BaseOS\Connect\Tenancy;

use BaseOS\Connect\Contracts\TenantScope;

final class NullTenantScope implements TenantScope
{
    public function enter(string $tenant): void {}

    public function leave(): void {}
}
