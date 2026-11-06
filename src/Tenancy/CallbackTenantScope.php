<?php

namespace BaseOS\Connect\Tenancy;

use BaseOS\Connect\Contracts\TenantScope;

/**
 * Closure-based scope, registered at runtime (closures cannot live in cached config):
 *
 *   BaseOSBridge::tenantUsing(function (string $tenant) {
 *       $previous = Site::current();
 *       Site::setCurrent($tenant);
 *       return fn () => Site::setCurrent($previous);   // optional restore
 *   });
 */
final class CallbackTenantScope implements TenantScope
{
    /** @var list<callable|null> */
    private array $restore = [];

    /** @param  callable(string): (callable|null|void)  $enter */
    public function __construct(private $enter, private $leave = null) {}

    public function enter(string $tenant): void
    {
        $result = ($this->enter)($tenant);
        $this->restore[] = is_callable($result) ? $result : null;
    }

    public function leave(): void
    {
        $restore = array_pop($this->restore);
        if ($restore) {
            $restore();
        } elseif ($this->leave) {
            ($this->leave)();
        }
    }
}
