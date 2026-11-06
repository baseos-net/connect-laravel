<?php

namespace BaseOS\Connect;

use BaseOS\Connect\Client\BaseOSClient;
use BaseOS\Connect\Commands\CommandDispatcher;
use BaseOS\Connect\Connections\ConfigConnectionStore;
use BaseOS\Connect\Connections\DatabaseConnectionStore;
use BaseOS\Connect\Console\ConnectionsCommand;
use BaseOS\Connect\Console\DisconnectCommand;
use BaseOS\Connect\Console\DoctorCommand;
use BaseOS\Connect\Console\HelloCommand;
use BaseOS\Connect\Console\PairCommand;
use BaseOS\Connect\Console\StatusCommand;
use BaseOS\Connect\Contracts\ConnectionStore;
use BaseOS\Connect\Contracts\ContentRepository;
use BaseOS\Connect\Contracts\IdempotencyStore;
use BaseOS\Connect\Contracts\SchemaProvider;
use BaseOS\Connect\Contracts\TenantResolver;
use BaseOS\Connect\Contracts\TenantScope;
use BaseOS\Connect\Http\Controllers\CommandController;
use BaseOS\Connect\Http\Controllers\HealthController;
use BaseOS\Connect\Http\Middleware\VerifyBaseOSSignature;
use BaseOS\Connect\Idempotency\CacheIdempotencyStore;
use BaseOS\Connect\Idempotency\DatabaseIdempotencyStore;
use BaseOS\Connect\Schema\DefaultSchemaProvider;
use BaseOS\Connect\Signing\Signer;
use BaseOS\Connect\Tenancy\ConnectionTenantResolver;
use BaseOS\Connect\Tenancy\NullTenantScope;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/baseos-connect.php', 'baseos-connect');

        $this->app->singleton(Signer::class, fn () => new Signer((int) config('baseos-connect.clock_tolerance', 300)));

        $this->app->singleton(ConnectionStore::class, function (Container $app) {
            $cfg = (array) config('baseos-connect.connections', []);

            return match ($cfg['driver'] ?? 'database') {
                'config' => new ConfigConnectionStore((array) ($cfg['config'] ?? [])),
                'database' => new DatabaseConnectionStore(
                    $app->make('db'),
                    $app->make('encrypter'),
                    (string) ($cfg['table'] ?? 'baseos_connect_connections'),
                    $cfg['db_connection'] ?? null,
                ),
                default => $app->make((string) $cfg['driver']),
            };
        });

        $this->app->singleton(IdempotencyStore::class, function (Container $app) {
            $cfg = (array) config('baseos-connect.idempotency', []);

            return ($cfg['driver'] ?? 'database') === 'cache'
                ? new CacheIdempotencyStore($app->make('cache')->store($cfg['cache_store'] ?? null))
                : new DatabaseIdempotencyStore(
                    $app->make('db'),
                    (string) ($cfg['table'] ?? 'baseos_connect_idempotency'),
                    config('baseos-connect.connections.db_connection'),
                );
        });

        $this->app->singleton(TenantResolver::class, function (Container $app) {
            $class = config('baseos-connect.tenant_resolver');
            if ($class) {
                return $app->make($class);
            }

            return new ConnectionTenantResolver(
                (array) config('baseos-connect.tenancy.hosts', []),
                (bool) config('baseos-connect.tenancy.from_host', false),
            );
        });

        $this->app->singleton(TenantScope::class, function (Container $app) {
            $class = config('baseos-connect.tenant_bootstrap');

            return $class ? $app->make($class) : new NullTenantScope;
        });

        $this->app->bind(SchemaProvider::class, function (Container $app) {
            $class = config('baseos-connect.schema_provider');

            return $class ? $app->make($class) : new DefaultSchemaProvider(
                (array) config('baseos-connect.schema', []),
                (array) config('baseos-connect.tenancy.labels', []),
                method_exists($app, 'version') ? $app->version() : null,
            );
        });

        if ($repository = config('baseos-connect.repository')) {
            $this->app->bind(ContentRepository::class, $repository);
        }

        $this->app->singleton(BaseOSClient::class, fn (Container $app) => new BaseOSClient(
            $app->make(\Illuminate\Http\Client\Factory::class),
            $app->make(Signer::class),
            (string) config('baseos-connect.base_url', 'https://baseos.net'),
            (int) config('baseos-connect.http_timeout', 20),
        ));

        $this->app->singleton(BridgeManager::class, fn (Container $app) => new BridgeManager($app));

        $this->app->bind(CommandDispatcher::class, function (Container $app) {
            $expose = config('baseos-connect.expose_errors');

            return new CommandDispatcher(
                $app->make(BridgeManager::class),
                $app->make(IdempotencyStore::class),
                (int) config('baseos-connect.idempotency.ttl', 604800),
                $expose === null ? (bool) config('app.debug', false) : (bool) $expose,
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/baseos-connect.php' => config_path('baseos-connect.php'),
        ], 'baseos-connect-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'baseos-connect-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('baseos-connect.routes.enabled', true)) {
            $this->registerRoutes();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                PairCommand::class,
                HelloCommand::class,
                StatusCommand::class,
                DisconnectCommand::class,
                DoctorCommand::class,
                ConnectionsCommand::class,
            ]);
        }
    }

    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        $group = [
            'prefix' => trim((string) config('baseos-connect.routes.prefix', 'baseos'), '/'),
            'middleware' => (array) config('baseos-connect.routes.middleware', ['api']),
        ];
        if ($domain = config('baseos-connect.routes.domain')) {
            $group['domain'] = $domain;
        }

        Route::group($group, function () {
            Route::post('commands', CommandController::class)
                ->middleware(VerifyBaseOSSignature::class)
                ->name('baseos.commands');
            Route::get('health', HealthController::class)->name('baseos.health');
        });
    }
}
