<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base OS API
    |--------------------------------------------------------------------------
    | Used by baseos:pair / baseos:sync and outbound events.
    | Client appends /api/v1/connect. That path is the live pair route on
    | https://baseos.net and https://baseos.dev (probed 2026-11-06).
    | BASEOS_CONNECT_BASE_URL wins; BASEOS_BASE_URL still works.
    */
    'base_url' => env('BASEOS_CONNECT_BASE_URL', env('BASEOS_BASE_URL', 'https://baseos.net')),

    'http_timeout' => (int) env('BASEOS_HTTP_TIMEOUT', 20),

    /*
    |--------------------------------------------------------------------------
    | Receiver routes
    |--------------------------------------------------------------------------
    | POST {prefix}/commands (signed) and GET {prefix}/health.
    | receiver_url sent at pair time = {APP_URL}/{prefix} (override with --receiver-url).
    */
    'routes' => [
        'enabled' => env('BASEOS_ROUTES', true),
        'prefix' => env('BASEOS_ROUTE_PREFIX', 'baseos'),
        'domain' => env('BASEOS_ROUTE_DOMAIN'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection storage
    |--------------------------------------------------------------------------
    | 'database' — table baseos_connect_connections (secret encrypted with Crypt).
    |              Required for multi-site (one connection per website).
    | 'config'   — single site, read from env; nothing is written.
    */
    'connections' => [
        'driver' => env('BASEOS_CONNECTIONS_DRIVER', 'database'),
        'table' => 'baseos_connect_connections',
        'db_connection' => env('BASEOS_DB_CONNECTION'),

        'config' => [
            'connection_id' => env('BASEOS_CONNECTION_ID'),
            'secret' => env('BASEOS_SECRET'),
            'tenant' => env('BASEOS_TENANT'),
            'site_url' => env('BASEOS_SITE_URL', env('APP_URL')),
            'receiver_url' => env('BASEOS_RECEIVER_URL'),
            'protocol' => env('BASEOS_PROTOCOL', '2.1'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Your code
    |--------------------------------------------------------------------------
    | repository       — class implementing BaseOS\Connect\Contracts\ContentRepository (required).
    | schema_provider  — class implementing BaseOS\Connect\Contracts\SchemaProvider (null = default).
    | tenant_resolver  — class implementing BaseOS\Connect\Contracts\TenantResolver
    |                    (null = tenant stored on the connection, then host fallback below).
    | tenant_bootstrap — class implementing BaseOS\Connect\Contracts\TenantScope, called
    |                    enter($tenant) before / leave() after every command.
    */
    'repository' => env('BASEOS_REPOSITORY'),
    'schema_provider' => null,
    'tenant_resolver' => null,
    'tenant_bootstrap' => null,

    /*
    |--------------------------------------------------------------------------
    | Tenancy (one codebase — many websites)
    |--------------------------------------------------------------------------
    | hosts     — map site host => tenant id, used when a connection has no stored tenant.
    | from_host — when no stored tenant and no map entry, use the site host itself as tenant.
    | labels    — optional tenant id => human label reported in schema.tenant.
    */
    'tenancy' => [
        'hosts' => [],
        'from_host' => (bool) env('BASEOS_TENANT_FROM_HOST', false),
        'labels' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default schema (used when schema_provider is null)
    |--------------------------------------------------------------------------
    | Merged into the schema sent with hello / site.schema. See README §Schema.
    */
    'schema' => [
        'content_formats' => ['html'],
        'post_types' => [],
        'taxonomies' => [],
        'fields' => [],
        'authors' => [],
        'locales' => [],
        'params_spec' => [],
        'defaults' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security & idempotency
    |--------------------------------------------------------------------------
    */
    'clock_tolerance' => (int) env('BASEOS_CLOCK_TOLERANCE', 300),

    'idempotency' => [
        'driver' => env('BASEOS_IDEMPOTENCY_DRIVER', 'database'), // database | cache
        'table' => 'baseos_connect_idempotency',
        'cache_store' => env('BASEOS_IDEMPOTENCY_CACHE'),
        'ttl' => 60 * 60 * 24 * 7,
    ],

    /*
    | Features announced in hello / site.health. Only list what your repository really does.
    | `post.validate` works out of the box (built-in checks + optional ValidatesPosts).
    */
    'features' => [
        'post.upsert', 'post.set_state', 'post.set_state.trash', 'post.fetch', 'post.find',
        'post.validate', 'site.schema', 'seo.meta',
    ],

    /*
    | Expose exception messages in error responses (default: app.debug).
    */
    'expose_errors' => env('BASEOS_EXPOSE_ERRORS'),
];
