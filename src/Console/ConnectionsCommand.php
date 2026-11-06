<?php

namespace BaseOS\Connect\Console;

use BaseOS\Connect\BridgeManager;
use Illuminate\Console\Command;

class ConnectionsCommand extends Command
{
    protected $aliases = ['baseos-connect:connections'];

    protected $signature = 'baseos:connections
        {--forget= : Delete a stored connection by id}
        {--json : Output JSON}';

    protected $description = 'List Base OS connections stored on this site';

    public function handle(BridgeManager $bridge): int
    {
        $store = $bridge->connections();

        if ($forget = $this->option('forget')) {
            if (! $store->writable()) {
                $this->error('Connection driver is read-only (config). Remove BASEOS_* from .env instead.');

                return self::FAILURE;
            }
            if (! $store->find((string) $forget)) {
                $this->error("Connection {$forget} not found.");

                return self::FAILURE;
            }
            if (! $this->confirm("Delete connection {$forget}? Base OS will no longer reach this site through it.", false)) {
                return self::SUCCESS;
            }
            $store->delete((string) $forget);
            $this->info("Deleted {$forget}. Also revoke it in Base OS.");

            return self::SUCCESS;
        }

        $rows = array_map(fn ($c) => [
            'connection_id' => $c->connectionId,
            'tenant' => $bridge->resolveTenant($c) ?? '—',
            'site_url' => $c->siteUrl ?? '—',
            'receiver_url' => $c->receiverUrl ?? '—',
            'protocol' => $c->protocol,
            'paired_at' => (string) ($c->meta('paired_at') ?? '—'),
        ], $store->all());

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->warn('No connection. Run php artisan baseos:pair {code}.');

            return self::SUCCESS;
        }

        $this->table(['Connection', 'Tenant', 'Site', 'Receiver', 'Protocol', 'Paired'], $rows);

        return self::SUCCESS;
    }
}
