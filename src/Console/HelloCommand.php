<?php

namespace BaseOS\Connect\Console;

use BaseOS\Connect\BridgeManager;
use Illuminate\Console\Command;

class HelloCommand extends Command
{
    protected $aliases = ['baseos:hello', 'baseos-connect:hello', 'baseos-connect:sync'];

    protected $signature = 'baseos:sync
        {--connection= : Connection id (default: every stored connection)}
        {--no-schema : Do not include the site schema}';

    protected $description = 'Send hello and the site schema to BaseOS (synchronise this website)';

    public function handle(BridgeManager $bridge): int
    {
        $id = $this->option('connection');
        $connections = $id ? array_filter([$bridge->connection((string) $id)]) : $bridge->connections()->all();

        if ($connections === []) {
            $this->error($id ? "Connection {$id} not found." : 'No connection. Run php artisan baseos:pair {code} first.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($connections as $connection) {
            $tenant = $bridge->resolveTenant($connection);
            $label = $connection->connectionId.($tenant !== null ? " (tenant {$tenant})" : '');
            try {
                $res = $bridge->hello($connection, ! $this->option('no-schema'));
                $this->info("{$label}: ".($res['status'] ?? 'ok').', protocol '.($res['protocol'] ?? '?'));
            } catch (\Throwable $e) {
                $failed++;
                $this->error("{$label}: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
