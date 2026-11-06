# Changelog

## 0.3.0 — 2026-11-06

- Product name is BaseOS Connect. Composer package is `baseos/connect-laravel`. Config file is `config/baseos-connect.php`. Tables are `baseos_connect_connections` and `baseos_connect_idempotency`. API path is `/api/v1/connect`.
- Artisan commands: `baseos:pair`, `baseos:status`, `baseos:sync`, `baseos:doctor`, `baseos:disconnect`, `baseos:connections`. `baseos-connect:*` is an alias for the same commands.
- Namespace is `BaseOS\Connect`.
- Default API origin is `https://baseos.net`. The client still calls `/api/v1/connect`. `BASEOS_CONNECT_BASE_URL` overrides `BASEOS_BASE_URL`.
- Config key `baseos-connect` mirrors `baseos-connect`. Publish tag `baseos-connect-config` writes the same `config/baseos-connect.php`.
- Facade `BaseOS\Connect\Facades\BaseOSConnect` uses the same manager as `BaseOS\Connect\Facades\BaseOSBridge`.

## 0.2.0 — 2026-11-05

- New command `post.validate` (button "Kiểm tra với website"): same data as `post.upsert`, returns `{valid, issues: [{code, path, message, blocking}]}`, writes nothing, never idempotency-cached, runs inside the tenant scope.
- Built-in checks (`BaseOS\Connect\Validation\PostValidator`): empty title/body, invalid state, unknown post type, schema-required fields per post type (`field.required`), required `params_spec` (`param.required`); warnings for unknown author / taxonomy / term refs / locale.
- Optional contract `BaseOS\Connect\Contracts\ValidatesPosts` — implement on your repository to add site-specific issues (merged; same `code` + `path` overrides the built-in one).
- `BridgePost::isTest()` — content ids starting with `baseos-test-` (Base OS test-draft fallback for receivers without `post.validate`); store normally, skip side effects.
- `post.validate` added to the default `features`. **Upgrade:** if you published `config/baseos-connect.php` before, add `'post.validate'` to `features` and run `php artisan baseos:hello`.

## 0.1.0 — 2026-11-05

First release. Protocol 2.1 (compatible with 2.0).

- Signed receiver routes: `POST {prefix}/commands`, `GET {prefix}/health`.
- Standard Webhooks verification per connection (`baseos-connection` header), ±300 s clock tolerance, previous-secret window.
- Commands: `post.upsert`, `post.set_state`, `post.fetch`, `post.find`, `site.health`, `site.schema`, `site.verify`.
- Idempotency per (connection, command type, `idempotency_key`), 7-day TTL, database or cache store; `post.find` reports `seen_key`.
- Developer contract: `ContentRepository`, `SchemaProvider`, `TenantResolver`, `TenantScope`; value objects `BridgePost`, `BridgeContext`, `BridgeConnection`, `RemoteResult`; `BridgeException`.
- Multi-tenant: tenant stored per connection (`--tenant`), host map / host fallback, tenant bootstrap enter/leave around every command.
- `EloquentContentRepository` helpers (term refs, fields_mask-aware fill, unique slugs), `SchemaBuilder`, `SchemaValidator`, `Blocks::toHtml()`.
- Artisan: `baseos:pair`, `baseos:hello`, `baseos:doctor`, `baseos:connections`.
- Outbound client: pair, hello (+ schema), events (`emitChanged` / `emitDeleted`).
