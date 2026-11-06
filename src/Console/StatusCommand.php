<?php

namespace BaseOS\Connect\Console;

use BaseOS\Connect\BridgeManager;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as Http;

class StatusCommand extends Command
{
    protected $aliases = ['baseos-connect:status'];

    protected $signature = 'baseos:status
        {--connection= : Connection id (default: every stored connection)}
        {--skip-http : Do not call the receiver /health endpoint}';

    protected $description = 'Show BaseOS Connect connections stored on this website';

    public function handle(BridgeManager $bridge, Http $http): int
    {
        $id = $this->option('connection');
        $rows = $id ? array_filter([$bridge->connection((string) $id)]) : $bridge->connections()->all();

        if ($rows === []) {
            $this->warn($id ? "Connection {$id} not found." : 'No connection. Run php artisan baseos:pair {code}.');

            return $id ? self::FAILURE : self::SUCCESS;
        }

        $table = [];
        foreach ($rows as $connection) {
            $health = 'skipped';
            if (! $this->option('skip-http') && $connection->receiverUrl) {
                $url = rtrim($connection->receiverUrl, '/').'/health';
                try {
                    $response = $http->timeout(8)->get($url);
                    $health = (string) $response->status();
                } catch (\Throwable $e) {
                    $health = 'unreachable';
                }
            }
            $table[] = [
                $connection->connectionId,
                $bridge->resolveTenant($connection) ?? '—',
                $connection->siteUrl ?? '—',
                $connection->receiverUrl ?? '—',
                $connection->protocol ?? '2.1',
                $health,
            ];
        }

        $this->table(['Connection', 'Tenant', 'Site', 'Receiver', 'Protocol', 'Health'], $table);

        return self::SUCCESS;
    }
}
