<?php

namespace BaseOS\Connect\Console;

use BaseOS\Connect\BridgeManager;
use BaseOS\Connect\Data\BridgeConnection;
use BaseOS\Connect\Exceptions\BridgeException;
use Illuminate\Console\Command;

class PairCommand extends Command
{
    protected $aliases = ['baseos-connect:pair'];

    protected $signature = 'baseos:pair
        {code : Pair code shown in Base OS (e.g. K7Q9-M2XR)}
        {--site= : Public URL of the website (default APP_URL)}
        {--tenant= : Tenant id of this website in a multi-site codebase}
        {--receiver-url= : Receiver base URL (default APP_URL/{prefix})}
        {--no-hello : Do not send hello + schema after pairing}';

    protected $description = 'Pair this site (or one tenant) with a Base OS project';

    public function handle(BridgeManager $bridge): int
    {
        $site = rtrim((string) ($this->option('site') ?: config('app.url')), '/');
        $receiver = rtrim((string) ($this->option('receiver-url') ?: $bridge->receiverUrl()), '/');
        $tenant = $this->option('tenant') !== null && $this->option('tenant') !== '' ? (string) $this->option('tenant') : null;

        foreach (['site' => $site, 'receiver-url' => $receiver] as $label => $url) {
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                $this->error("Invalid {$label}: \"{$url}\". Set APP_URL or pass --{$label}=https://…");

                return self::FAILURE;
            }
            if (! str_starts_with($url, 'https://')) {
                $this->warn("{$label} is not HTTPS ({$url}). Base OS only calls HTTPS receivers unless CMS_BRIDGE_ALLOW_HTTP is enabled on the server.");
            }
        }

        $this->line("Base OS:  {$bridge->client()->baseUrl()}");
        $this->line("Site:     {$site}");
        $this->line("Receiver: {$receiver}/commands");
        if ($tenant !== null) {
            $this->line("Tenant:   {$tenant}");
        }

        try {
            $res = $bridge->client()->pair((string) $this->argument('code'), $site, $receiver);
        } catch (BridgeException $e) {
            $this->error("Pairing failed [{$e->errorCode}]: {$e->getMessage()}");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Pairing failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $connection = new BridgeConnection(
            connectionId: (string) $res['connection_id'],
            secret: (string) $res['secret'],
            tenant: $tenant,
            siteUrl: $site,
            receiverUrl: $receiver,
            protocol: (string) ($res['protocol'] ?? '2.1'),
            meta: array_filter([
                'status' => $res['status'] ?? null,
                'events_url' => $res['events_url'] ?? null,
                'poll_url' => $res['poll_url'] ?? null,
                'paired_at' => gmdate('c'),
                'base_url' => $bridge->client()->baseUrl(),
            ]),
        );

        $store = $bridge->connections();
        if ($store->writable()) {
            $store->save($connection);
            $this->info("Paired. Connection {$connection->connectionId} stored (secret encrypted).");
        } else {
            $this->info("Paired. Connection driver is \"config\" — add to .env:");
            $this->line('');
            $this->line("BASEOS_CONNECTION_ID={$connection->connectionId}");
            $this->line("BASEOS_SECRET={$connection->secret()}");
            if ($tenant !== null) {
                $this->line("BASEOS_TENANT={$tenant}");
            }
            $this->line("BASEOS_SITE_URL={$site}");
            $this->line("BASEOS_RECEIVER_URL={$receiver}");
            $this->line('');
            $this->warn('The secret is shown once. Run `php artisan config:clear` after editing .env.');
        }

        if (($res['status'] ?? null) === 'pending_confirm') {
            $this->warn('Status pending_confirm: the site host differs from the project domain — confirm the connection in Base OS.');
        }

        if ($this->option('no-hello')) {
            return self::SUCCESS;
        }

        try {
            $hello = $bridge->hello($connection);
            $this->info('Hello sent with schema. Status: '.($hello['status'] ?? '?').', protocol: '.($hello['protocol'] ?? '?'));
        } catch (\Throwable $e) {
            $this->warn('Paired, but hello failed: '.$e->getMessage());
            $this->line('Fix the issue, then run: php artisan baseos:hello --connection='.$connection->connectionId);
        }

        return self::SUCCESS;
    }
}
