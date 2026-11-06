<?php

/*
 * Example 2a — tenant bootstrap for "one codebase, many websites".
 *
 * Typical pattern this targets: every content row carries a discriminator column
 * (`site_id`, `project_id`, `team_id`…) and a global scope filters by the "current site"
 * kept in some request-wide context (here: App\Support\CurrentSite — replace with yours:
 * a ProjectContext::set($id), Tenancy::initialize($tenant), a config value, a DB switch…).
 *
 * Base OS never sends the tenant. Each website is its own Base OS project/connection;
 * the tenant is fixed when pairing:  php artisan baseos:pair ABCD-1234 --site=https://site-a.com --tenant=site-a
 *
 * config/baseos-connect.php:  'tenant_bootstrap' => App\BaseOS\ProjectTenantBootstrap::class
 */

namespace App\BaseOS;

use App\Models\Site;
use App\Support\CurrentSite;
use BaseOS\Connect\Contracts\TenantScope;
use BaseOS\Connect\Exceptions\BridgeException;

class ProjectTenantBootstrap implements TenantScope
{
    /** @var list<int|string|null> previous current-site ids (enter/leave may nest) */
    private array $stack = [];

    public function enter(string $tenant): void
    {
        // The tenant string is whatever you stored at pair time: a slug, a numeric id, a host…
        $site = Site::query()
            ->withoutGlobalScopes()
            ->where(fn ($q) => $q->where('key', $tenant)->orWhere('domain', $tenant))
            ->first();

        if (! $site) {
            // Structured, non-retryable error back to Base OS: "no website matches this connection".
            throw BridgeException::tenantUnknown("Unknown site \"{$tenant}\".");
        }

        $this->stack[] = CurrentSite::id();
        CurrentSite::set($site->id);

        // Anything else that is per-site in your app goes here, e.g.:
        //   config(['app.url' => 'https://'.$site->domain]);   // so route()/url() build the right links
        //   URL::forceRootUrl('https://'.$site->domain);
        //   app()->setLocale($site->locale);
    }

    public function leave(): void
    {
        CurrentSite::set(array_pop($this->stack));
    }
}
