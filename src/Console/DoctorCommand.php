<?php

namespace BaseOS\Connect\Console;

use BaseOS\Connect\BridgeManager;
use BaseOS\Connect\Contracts\ContentRepository;
use BaseOS\Connect\Contracts\TenantScope;
use BaseOS\Connect\Schema\SchemaValidator;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class DoctorCommand extends Command
{
    protected $aliases = ['baseos-connect:doctor'];

    protected $signature = 'baseos:doctor
        {--skip-http : Do not call the receiver / Base OS over the network}';

    protected $description = 'Check the BaseOS Connect setup (config, URL, routes, clock, repository, schema)';

    private int $errors = 0;

    private int $warnings = 0;

    public function handle(BridgeManager $bridge, Http $http): int
    {
        $cfg = (array) config('baseos-connect', []);
        $http = $http->timeout(8);

        $this->section('Configuration');
        $this->check($cfg !== [], 'config/baseos-connect.php loaded', 'Config missing — is the provider discovered?');
        $this->check(filter_var($cfg['base_url'] ?? '', FILTER_VALIDATE_URL) !== false, 'base_url = '.($cfg['base_url'] ?? ''), 'base_url invalid');
        $appUrl = (string) config('app.url');
        $this->check(str_starts_with($appUrl, 'https://'), "APP_URL = {$appUrl}", "APP_URL is not HTTPS ({$appUrl}) — Base OS only pushes to HTTPS receivers", warn: true);

        $this->section('Storage');
        $driver = (string) ($cfg['connections']['driver'] ?? 'database');
        $this->line("  connections driver: {$driver}");
        if ($driver === 'database') {
            $this->check($this->hasTable($cfg['connections']['table'] ?? 'baseos_connect_connections', $cfg['connections']['db_connection'] ?? null), 'connections table exists', 'connections table missing — run php artisan migrate');
        }
        if (($cfg['idempotency']['driver'] ?? 'database') === 'database') {
            $this->check($this->hasTable($cfg['idempotency']['table'] ?? 'baseos_connect_idempotency', $cfg['connections']['db_connection'] ?? null), 'idempotency table exists', 'idempotency table missing — run php artisan migrate');
        }

        $this->section('Routes');
        $commands = Route::has('baseos.commands');
        $this->check($commands, 'POST '.$bridge->receiverUrl().'/commands registered', 'Receiver routes not registered (baseos-connect.routes.enabled?)');
        $this->check(Route::has('baseos.health'), 'GET '.$bridge->receiverUrl().'/health registered', 'Health route not registered');

        $this->section('Your code');
        $repoClass = $cfg['repository'] ?? null;
        $repoOk = false;
        try {
            $repoOk = app()->bound(ContentRepository::class) && app(ContentRepository::class) instanceof ContentRepository;
        } catch (\Throwable $e) {
            $this->line('  '.$e->getMessage());
        }
        $this->check($repoOk, 'repository = '.($repoClass ?: 'bound in container'), 'No ContentRepository — set baseos-connect.repository or BASEOS_REPOSITORY');
        $bootstrap = $cfg['tenant_bootstrap'] ?? null;
        if ($bootstrap) {
            $this->check(is_string($bootstrap) && class_exists($bootstrap) && is_subclass_of($bootstrap, TenantScope::class), "tenant_bootstrap = {$bootstrap}", 'tenant_bootstrap must be a class implementing '.TenantScope::class);
        }

        $this->section('Connections');
        $connections = [];
        try {
            $connections = $bridge->connections()->all();
        } catch (\Throwable $e) {
            $this->check(false, '', 'Cannot read connections: '.$e->getMessage());
        }
        $this->check($connections !== [], count($connections).' connection(s)', 'No connection — run php artisan baseos:pair {code}', warn: true);

        $tenants = [];
        foreach ($connections as $c) {
            $tenant = $bridge->resolveTenant($c);
            $this->line("  • {$c->connectionId}".($tenant !== null ? " → tenant {$tenant}" : '')." ({$c->siteUrl})");
            $this->check($c->secret() !== '', 'secret readable', 'secret cannot be decrypted (APP_KEY changed?) — pair again');
            if (count($connections) > 1) {
                $this->check($tenant !== null, 'tenant resolved', 'several connections but no tenant — every website needs a tenant (--tenant=)', warn: true);
                if ($tenant !== null && isset($tenants[$tenant])) {
                    $this->check(false, '', "tenant {$tenant} is used by two connections ({$tenants[$tenant]})", warn: true);
                }
                $tenants[$tenant ?? ''] = $c->connectionId;
            }

            try {
                $schema = $bridge->schema($bridge->context($c));
                $problems = SchemaValidator::validate($schema);
                $this->check($problems === [], 'schema valid ('.count((array) ($schema['taxonomies'] ?? [])).' taxonomies, '.count((array) ($schema['params_spec'] ?? [])).' params_spec)', 'schema problems:');
                foreach ($problems as $p) {
                    $this->line("      - {$p}");
                }
            } catch (\Throwable $e) {
                $this->check(false, '', 'schema provider threw: '.$e->getMessage());
            }
        }

        if (! $this->option('skip-http')) {
            $this->section('Network');
            $healthUrl = $bridge->receiverUrl().'/health';
            try {
                $r = $http->acceptJson()->get($healthUrl);
                $this->check($r->successful() && ($r->json('flavor') === 'laravel'), "receiver reachable ({$healthUrl})", "receiver health returned HTTP {$r->status()} ({$healthUrl})");
            } catch (\Throwable $e) {
                $this->check(false, '', "receiver not reachable at {$healthUrl}: {$e->getMessage()}", warn: true);
            }

            $base = rtrim((string) ($cfg['base_url'] ?? ''), '/');
            try {
                $r = $http->get($base.'/up');
                $date = $r->header('Date');
                if ($date && ($remote = strtotime($date)) !== false) {
                    $skew = abs(time() - $remote);
                    $tol = (int) ($cfg['clock_tolerance'] ?? 300);
                    $this->check($skew < $tol / 2, "clock skew {$skew}s vs Base OS", "clock skew {$skew}s vs Base OS (tolerance {$tol}s) — enable NTP", warn: $skew < $tol);
                } else {
                    $this->check(false, '', 'Base OS reachable but no Date header — clock not checked', warn: true);
                }
            } catch (\Throwable $e) {
                $this->check(false, '', "Base OS not reachable at {$base}: {$e->getMessage()}", warn: true);
            }
        }

        $this->line('');
        if ($this->errors > 0) {
            $this->error("{$this->errors} error(s), {$this->warnings} warning(s).");

            return self::FAILURE;
        }
        $this->info("All good ({$this->warnings} warning(s)).");

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line("<comment>{$title}</comment>");
    }

    private function check(bool $ok, string $okText, string $failText, bool $warn = false): void
    {
        if ($ok) {
            $this->line("  <info>✓</info> {$okText}");

            return;
        }
        if ($warn) {
            $this->warnings++;
            $this->line("  <comment>!</comment> {$failText}");

            return;
        }
        $this->errors++;
        $this->line("  <error>✗</error> {$failText}");
    }

    private function hasTable(string $table, ?string $connection): bool
    {
        try {
            return Schema::connection($connection)->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
