<?php

namespace BaseOS\Connect;

use BaseOS\Connect\Client\BaseOSClient;
use BaseOS\Connect\Contracts\ConnectionStore;
use BaseOS\Connect\Contracts\ContentRepository;
use BaseOS\Connect\Contracts\SchemaProvider;
use BaseOS\Connect\Contracts\TenantResolver;
use BaseOS\Connect\Contracts\TenantScope;
use BaseOS\Connect\Data\BridgeConnection;
use BaseOS\Connect\Data\BridgeContext;
use BaseOS\Connect\Exceptions\BridgeException;
use BaseOS\Connect\Tenancy\CallbackTenantScope;
use Illuminate\Contracts\Container\Container;

/**
 * Entry point for app code (also via the BaseOSBridge facade):
 *
 *   BaseOSBridge::tenantUsing(fn (string $tenant) => Site::switchTo($tenant));
 *   BaseOSBridge::emitChanged($connectionId, remoteId: '42', changed: ['title']);
 *   BaseOSBridge::connectionForTenant('site-3');
 */
class BridgeManager
{
    public function __construct(protected Container $app) {}

    public function connections(): ConnectionStore
    {
        return $this->app->make(ConnectionStore::class);
    }

    public function client(): BaseOSClient
    {
        return $this->app->make(BaseOSClient::class);
    }

    public function repository(): ContentRepository
    {
        if (! $this->app->bound(ContentRepository::class)) {
            throw new BridgeException('receiver.not_configured', 'No ContentRepository configured (baseos-connect.repository).', false, 500);
        }

        return $this->app->make(ContentRepository::class);
    }

    public function connection(string $connectionId): ?BridgeConnection
    {
        return $this->connections()->find($connectionId);
    }

    public function connectionForTenant(?string $tenant): ?BridgeConnection
    {
        foreach ($this->connections()->all() as $c) {
            if ($this->resolveTenant($c) === $tenant) {
                return $c;
            }
        }

        return null;
    }

    public function resolveTenant(BridgeConnection $connection): ?string
    {
        return $this->app->make(TenantResolver::class)->resolve($connection);
    }

    public function context(BridgeConnection $connection, ?string $protocol = null, ?string $commandId = null, ?string $idempotencyKey = null): BridgeContext
    {
        return new BridgeContext(
            connection: $connection,
            tenant: $this->resolveTenant($connection),
            protocol: $protocol ?: $connection->protocol,
            commandId: $commandId,
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Run $callback inside the tenant scope (enter/leave) when a tenant is resolved.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withinTenant(BridgeContext $ctx, callable $callback): mixed
    {
        if ($ctx->tenant === null || $ctx->tenant === '') {
            return $callback();
        }

        $scope = $this->app->make(TenantScope::class);
        $scope->enter($ctx->tenant);
        try {
            return $callback();
        } finally {
            $scope->leave();
        }
    }

    /** Register a closure tenant scope at runtime (e.g. in AppServiceProvider::boot). */
    public function tenantUsing(callable $enter, ?callable $leave = null): void
    {
        $this->app->instance(TenantScope::class, new CallbackTenantScope($enter, $leave));
    }

    /** @return array<string, mixed> */
    public function schema(BridgeContext $ctx, bool $enterTenant = true): array
    {
        $build = fn () => $this->app->make(SchemaProvider::class)->schema($ctx);

        return $enterTenant ? $this->withinTenant($ctx, $build) : $build();
    }

    /** @return list<string> */
    public function features(): array
    {
        return array_values(array_unique(array_map('strval', (array) config('baseos-connect.features', []))));
    }

    public function receiverUrl(?string $base = null): string
    {
        $base = rtrim($base ?: (string) config('app.url'), '/');

        return $base.'/'.trim((string) config('baseos-connect.routes.prefix', 'baseos'), '/');
    }

    /** @return array<string, mixed> */
    public function helloPayload(BridgeConnection $connection, bool $withSchema = true): array
    {
        $ctx = $this->context($connection);
        $payload = [
            'flavor' => Bridge::FLAVOR,
            'receiver_version' => Bridge::VERSION,
            'protocol_supported' => Bridge::PROTOCOLS,
            'features' => $this->features(),
            'cms' => [
                'name' => 'laravel',
                'version' => method_exists($this->app, 'version') ? $this->app->version() : null,
                'php' => PHP_VERSION,
                'multisite' => $ctx->tenant !== null,
            ],
            'site_url' => $connection->siteUrl,
            'receiver_url' => $connection->receiverUrl,
            'timezone' => (string) config('app.timezone', 'UTC'),
            'server_time' => gmdate('c'),
        ];
        if ($withSchema) {
            $payload['schema'] = $this->schema($ctx);
        }

        return array_filter($payload, fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> Base OS response: {status, protocol, settings, min_receiver} */
    public function hello(BridgeConnection $connection, bool $withSchema = true): array
    {
        $response = $this->client()->hello($connection, $this->helloPayload($connection, $withSchema));

        $protocol = $response['protocol'] ?? null;
        if (is_string($protocol) && $protocol !== '' && $protocol !== $connection->protocol && $this->connections()->writable()) {
            $this->connections()->save(new BridgeConnection(
                $connection->connectionId,
                $connection->secret(),
                $connection->tenant,
                $connection->siteUrl,
                $connection->receiverUrl,
                $protocol,
                $connection->meta,
            ));
        }

        return $response;
    }

    /**
     * Report an edit made on the site to a managed post (Base OS re-syncs if hashes differ).
     *
     * @param  list<string>  $changed
     * @param  array<string, mixed>|null  $actor
     * @return array{accepted?: int, duplicates?: int}
     */
    public function emitChanged(BridgeConnection|string $connection, string $remoteId, ?string $contentId = null, array $changed = [], ?string $remoteHash = null, ?array $actor = null): array
    {
        return $this->emit($connection, 'post.changed', $remoteId, $contentId, $changed, $remoteHash, $actor);
    }

    /** @return array{accepted?: int, duplicates?: int} */
    public function emitDeleted(BridgeConnection|string $connection, string $remoteId, ?string $contentId = null): array
    {
        return $this->emit($connection, 'post.deleted', $remoteId, $contentId);
    }

    /**
     * @param  list<string>  $changed
     * @param  array<string, mixed>|null  $actor
     * @return array<string, mixed>
     */
    protected function emit(BridgeConnection|string $connection, string $type, string $remoteId, ?string $contentId = null, array $changed = [], ?string $remoteHash = null, ?array $actor = null): array
    {
        $conn = is_string($connection) ? $this->connection($connection) : $connection;
        if (! $conn) {
            throw new BridgeException('auth.connection_unknown', 'Unknown Base OS connection.', false, 404);
        }

        return $this->client()->events($conn, [array_filter([
            'id' => 'ev_'.bin2hex(random_bytes(12)),
            'type' => $type,
            'remote_id' => $remoteId,
            'content_id' => $contentId,
            'changed' => $changed ?: null,
            'remote_hash' => $remoteHash,
            'occurred_at' => gmdate('c'),
            'actor' => $actor,
        ], fn ($v) => $v !== null)]);
    }
}
