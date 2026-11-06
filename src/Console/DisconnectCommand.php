<?php

namespace BaseOS\Connect\Console;

use BaseOS\Connect\BridgeManager;
use Illuminate\Console\Command;

class DisconnectCommand extends Command
{
    protected $aliases = ['baseos-connect:disconnect'];

    protected $signature = 'baseos:disconnect
        {id : Connection id stored on this website}';

    protected $description = 'Remove a BaseOS Connect credential from this website';

    public function handle(BridgeManager $bridge): int
    {
        $store = $bridge->connections();
        $id = (string) $this->argument('id');

        if (! $store->writable()) {
            $this->error('Connection driver is read-only (config). Remove BASEOS_CONNECTION_ID and BASEOS_SECRET from .env, then revoke the connection in BaseOS.');

            return self::FAILURE;
        }

        if (! $store->find($id)) {
            $this->error("Connection {$id} not found.");

            return self::FAILURE;
        }

        if (! $this->confirm("Delete connection {$id} on this website? BaseOS will no longer be able to sign in with it. Revoke it in BaseOS as well.", false)) {
            return self::SUCCESS;
        }

        $store->delete($id);
        $this->info("Deleted {$id}. Revoke the same connection in BaseOS so the credential cannot be reused.");

        return self::SUCCESS;
    }
}
