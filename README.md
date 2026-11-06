# baseos/connect-laravel

Receiver **BaseOS Connect** (Protocol 2.1, tương thích 2.0) cho **mọi website Laravel tự code** — Laravel 10–13, PHP ≥ 8.1.
Chạy được cho **một website** và cho **một mã nguồn phục vụ nhiều website** (multi-tenant).

> English summary at the end.

Bạn chỉ cần viết **một class repository** (4 hàm) nói cho package biết bài viết lưu ở đâu. Package lo: route, kiểm chữ ký, chống gửi lặp (idempotency), phân giải website (tenant), báo cấu trúc site, ghép nối với Base OS.

```
Base OS ── POST {APP_URL}/baseos/commands (ký Standard Webhooks) ──▶ VerifyBaseOSSignature
                                                                    ─▶ CommandDispatcher (idempotency, tenant enter/leave)
                                                                    ─▶ ContentRepository của bạn
```

---

## 1. Cài đặt

```bash
composer require baseos/connect-laravel
php artisan vendor:publish --tag=baseos-connect-config
php artisan migrate        # bảng baseos_connect_connections + baseos_connect_idempotency
```

Chưa có trên Packagist? Dùng path repository trong `composer.json` của app:

```json
"repositories": [{ "type": "path", "url": "../baseos/packages/baseos-connect/laravel" }]
```

`.env` tối thiểu:

```dotenv
APP_URL=https://example.vn          # phải là HTTPS — Base OS chỉ gọi receiver HTTPS
BASEOS_REPOSITORY=App\BaseOS\PostRepository
# BASEOS_CONNECT_BASE_URL=https://baseos.net
```

## 2. Viết repository

```php
namespace App\BaseOS;

use BaseOS\Bridge\Data\{BridgeContext, BridgePost, RemoteResult};
use BaseOS\Bridge\Exceptions\BridgeException;
use BaseOS\Bridge\Repository\EloquentContentRepository;

class PostRepository extends EloquentContentRepository
{
    public function upsert(BridgePost $post, ?string $remoteId): RemoteResult
    {
        $row = $remoteId ? Post::find($remoteId) : Post::firstWhere('baseos_content_id', $post->contentId());
        $row ??= new Post;
        $row->fill([
            'title' => $post->title(),
            'slug' => $post->slug(),
            'body' => $post->bodyHtml(),
            'status' => $post->isPublished() ? 'published' : 'draft',
            'baseos_content_id' => $post->contentId(),
        ])->save();

        return new RemoteResult((string) $row->id, route('posts.show', $row->slug), null, $post->state());
    }

    public function setState(string $remoteId, string $state, BridgeContext $ctx): RemoteResult { /* published|draft|unpublished|trashed */ }
    public function find(string $contentId, BridgeContext $ctx): ?RemoteResult { /* tìm theo baseos_content_id */ }
    public function fetch(string $remoteId, BridgeContext $ctx): ?RemoteResult { /* null = không tồn tại */ }
}
```

Quy tắc quan trọng:

- **`upsert` phải idempotent theo `contentId()`**: khi `$remoteId === null` nhưng đã có bản ghi cùng content id → cập nhật, không tạo trùng.
- Lỗi có chủ đích → `throw BridgeException::notFound()`, `::invalid('…')`, `::slugTaken($slug)`, `::locked()`, `::busy()`, hoặc `new BridgeException('code', 'message', retryable: true)`. Exception khác → `internal` (retryable, ẩn message trừ khi `APP_DEBUG`).
- Package tự bổ sung `remote_hash`, `revision_seen`, `content_id` từ bài nếu bạn để trống.

Ví dụ đầy đủ: [`examples/SingleSiteRepository.php`](./examples/SingleSiteRepository.php).

### `BridgePost` — đọc dữ liệu

| Hàm | Nguồn |
|-----|-------|
| `title()` `slug()` `excerpt()` `bodyHtml()` `locale()` `contentId()` `revision()` `contentHash()` `publishedAt()` | `content.*` (contract v2) |
| `bodyBlocks()` | `content.body_blocks` (khi mapping `body_format = blocks`), `null` nếu không có |
| `seo($key = null)` `featuredMedia()` `inlineMedia()` `taxonomy()` `content('seo.title')` | `content.*` |
| `state()` `isPublished()` `remoteId()` `mappingVersion()` `fieldsMask()` `manages('title')` `options()` | envelope `data` |
| `isTest()` | `content.content_id` bắt đầu bằng `baseos-test-` (bài đăng thử) |
| `target('terms.category.0.ref')` `postType()` `author()` `bodyFormat()` `mediaPolicy()` | `target.*` (Base OS đã ghép sẵn theo kết nối) |
| `terms('category')` → `[{ref:"term:12"}]` hoặc `[{name, slug, create:true}]` | `target.terms[tax]`; receiver 2.0: suy từ `content.taxonomy` |
| `fields()` / `field('meta:rating')` | `target.fields` |
| `params()` / `param('site_id', $default)` | `params` — tham số tùy chọn của kết nối, đã ép kiểu |
| `tenant()` `context()` | website đã phân giải + `BridgeContext` |

`locale()` ưu tiên `target.locale` (ghi đè trong mapping) rồi mới tới `content.locale`. `bodyHtml()` tự render từ block nếu chỉ có block.

**Không ghi `params` mù vào DB** — dùng cho logic (kênh, kiểm tra an toàn…).

### Term / chuyên mục

Base OS gửi lại đúng `ref` mà schema của bạn đã báo (vd. `term:12`). Chỉ tạo term mới khi item có `create: true`.

```php
$ids = $this->resolveTermIds($post->terms('category'),
    findById:   fn (string $id) => Category::whereKey($id)->value('id'),
    findBySlug: fn (string $slug, string $name) => Category::where('slug', $slug)->value('id'),
    create:     fn (string $name, string $slug) => Category::create(compact('name', 'slug'))->id,
);
```

Helper khác của `EloquentContentRepository`: `parseRef()`, `ref()`, `fillManaged()` (tôn trọng `fields_mask`), `uniqueSlug()`, `transaction()`, `result()`, `blocksToHtml()`.

### Kiểm tra với website (`post.validate`)

Nút **Kiểm tra với website** của Base OS gửi `post.validate` — cùng dữ liệu với `post.upsert`, trả `{valid, issues: [{code, path, message, blocking}]}`, **không ghi gì**, không cache idempotency, chạy trong tenant `enter()`/`leave()`.

Có sẵn, không cần code (`BaseOS\Bridge\Validation\PostValidator`, dựa trên schema của đúng website):

| code | Chặn | Khi |
|------|:---:|-----|
| `content.title_required` / `content.body_required` / `state.invalid` | ✓ | Tiêu đề / nội dung trống, state sai |
| `post_type.unknown` | ✓ | Loại bài không có trong `schema.post_types` |
| `field.required` | ✓ | `schema.fields[].required` (theo `post_types`, bỏ qua trường có `default`) thiếu trong `target.fields` |
| `param.required` | ✓ | `params_spec[].required` thiếu |
| `author.unknown` · `taxonomy.unknown` · `term.not_found` · `term.missing` · `locale.unknown` | – | Không khớp schema (cảnh báo) |

Thêm kiểm tra riêng → implement `ValidatesPosts` trên repository (issue trùng `code` + `path` sẽ thay issue dựng sẵn; `BridgeException` ném ra thành một issue chặn):

```php
use BaseOS\Bridge\Contracts\ValidatesPosts;

class PostRepository extends EloquentContentRepository implements ValidatesPosts
{
    public function validate(BridgePost $post, ?string $remoteId): array
    {
        return $post->isPublished() && ! $post->featuredMedia()
            ? [['code' => 'media.featured_required', 'path' => 'content.featured_media', 'message' => 'Cần ảnh đại diện khi đăng công khai.']]
            : [];
    }
}
```

**Bài đăng thử:** receiver chưa có `post.validate` sẽ nhận bản nháp với content id `baseos-test-…` rồi `trashed` ngay. `$post->isTest()` = true → lưu bình thường, bỏ qua side effect (xóa cache, thông báo, ping sitemap). Ví dụ đầy đủ: [`examples/SingleSiteRepository.php`](./examples/SingleSiteRepository.php).

### Body dạng block

Khi kết nối đặt `body_format = blocks`, `content.body_blocks` là:

| `type` | Thuộc tính |
|--------|-----------|
| `h2` `h3` `h4` | `id` (slug duy nhất trong bài), `text` (inline HTML) |
| `p` | `text` |
| `ul` `ol` | `items[]` |
| `image` | `src`, `alt`, `caption` |
| `quote` | `text` |
| `html` | `html` (bảng, SVG, phần không quy đổi) |

Inline HTML đã được Base OS lọc: `a strong b em i u code br sup sub mark s`.
Lưu nguyên JSON, hoặc render: `BaseOS\Bridge\Support\Blocks::toHtml($blocks, ['figure_class' => '…', 'strip_unsafe' => true])`. Thêm `Blocks::headings()` (mục lục) và `Blocks::toText()`.

## 3. Ghép nối (pair)

Trong Base OS: mở dự án → **API & kết nối** (`/app/api`) → **Kết nối website** → **Website tự code** → lấy mã ghép (15 phút).

```bash
php artisan baseos-connect:pair K7Q9-M2XR
```

Lệnh gọi `POST {base_url}/api/v1/connect/pair` với `site_url` (`--site` hoặc `APP_URL`), `receiver_url` (`--receiver-url` hoặc `APP_URL/{prefix}`), `flavor=laravel`, `protocol_supported=["2.1","2.0"]`; lưu `connection_id` + secret (mã hóa bằng `Crypt`), rồi gửi `hello` kèm schema.

```bash
php artisan baseos-connect:hello                 # gửi lại hello + schema (mọi kết nối) — chạy sau khi đổi chuyên mục/cấu hình
php artisan baseos-connect:hello --connection=sc_…
php artisan baseos-connect:connections           # liệt kê; --forget=sc_… để xóa
php artisan baseos-connect:doctor                # kiểm tra cấu hình, HTTPS, route, đồng hồ, repository, schema
```

**Một site, không muốn dùng DB?** `BASEOS_CONNECTIONS_DRIVER=config`: `baseos-connect:pair` in ra các dòng `BASEOS_CONNECTION_ID` / `BASEOS_SECRET` để dán vào `.env`. Nên đặt thêm `BASEOS_IDEMPOTENCY_DRIVER=cache` nếu không chạy migration.

> `APP_KEY` mã hóa secret. Đổi `APP_KEY` ⇒ phải ghép lại (`baseos-connect:doctor` sẽ báo).

## 4. Schema (cấu trúc site)

Schema giúp Base OS tự ghép chuyên mục, biết trường bắt buộc, hiển thị website đích, khai báo tham số. Gửi trong `hello` và khi người dùng bấm **Quét cấu trúc** (`site.schema`).

Mặc định: lấy từ `config('baseos-connect.schema')` + tenant. Muốn báo term thật → implement `SchemaProvider`:

```php
use BaseOS\Bridge\Schema\SchemaBuilder;

class MySchema implements \BaseOS\Bridge\Contracts\SchemaProvider
{
    public function schema(BridgeContext $ctx): array
    {
        return SchemaBuilder::base(app()->version())
            ->postType('post', 'Bài viết', ['category', 'post_tag'])
            ->taxonomy('category', 'Chuyên mục', Category::all()->map(fn ($c) => [
                'ref' => 'term:'.$c->id, 'name' => $c->name, 'slug' => $c->slug,
            ]), hierarchical: true, role: 'category')
            ->param('channel', 'Kênh', 'string', enum: ['blog', 'news'], default: 'blog')
            ->tenant($ctx->tenant)
            ->toArray();
    }
}
```

Giới hạn phía Base OS: 30 taxonomy, 1000 term/taxonomy, 200 field/author, 50 `params_spec`. `SchemaValidator::validate()` (dùng trong `baseos-connect:doctor`) báo trước các lỗi này. Ví dụ: [`examples/schema-example.php`](./examples/schema-example.php).

## 5. Nhiều website trên một mã nguồn

Mỗi website = **một project Base OS = một kết nối**. Base OS **không gửi tenant**; receiver tự biết website từ header `baseos-connection`. Mọi website có thể dùng chung một receiver URL.

**Bước 1 — ghép từng website với tenant của nó:**

```bash
php artisan baseos-connect:pair AAAA-1111 --site=https://site-a.com --tenant=site-a
php artisan baseos-connect:pair BBBB-2222 --site=https://site-b.com --tenant=site-b
```

Phân giải tenant mặc định (`ConnectionTenantResolver`): tenant lưu lúc pair → `tenancy.hosts` (`'site-a.com' => 'site-a'`) → host của site nếu `tenancy.from_host = true` → `null`. Tự viết: implement `TenantResolver` và đặt `tenant_resolver`.

**Bước 2 — chuyển ngữ cảnh app sang website đó** trước khi repository chạy. App dùng global scope theo "site hiện tại" (vd. `CurrentSite::set($id)`, `ProjectContext::set($id)`, `Tenancy::initialize()`…):

```php
// config/baseos-connect.php
'tenant_bootstrap' => App\BaseOS\SiteTenantScope::class,

class SiteTenantScope implements \BaseOS\Bridge\Contracts\TenantScope
{
    private array $stack = [];

    public function enter(string $tenant): void
    {
        $site = Site::withoutGlobalScopes()->where('key', $tenant)->first()
            ?? throw \BaseOS\Bridge\Exceptions\BridgeException::tenantUnknown();
        $this->stack[] = CurrentSite::id();
        CurrentSite::set($site->id);
    }

    public function leave(): void
    {
        CurrentSite::set(array_pop($this->stack));   // luôn chạy (finally)
    }
}
```

Hoặc đăng ký closure lúc chạy (trong `AppServiceProvider::boot`):

```php
BaseOSBridge::tenantUsing(function (string $tenant) {
    $prev = CurrentSite::id();
    CurrentSite::set(Site::idForKey($tenant));
    return fn () => CurrentSite::set($prev);          // khôi phục
});
```

`enter()` chạy quanh **mọi lệnh** (upsert, validate, set_state, find, fetch, site.schema) và khi `baseos-connect:hello` dựng schema. Vì vậy `SchemaProvider` chỉ thấy chuyên mục của đúng website.

Lưu ý cho multi-site:

- Unique theo website: `(site_id, baseos_content_id)`, `(site_id, slug)`.
- Dựng `url` trả về theo **domain của website**, không theo `APP_URL`.
- Có thể khai báo param `site_id` trong `params_spec` và từ chối nếu không khớp — chặn nhầm website.

Ví dụ đầy đủ (block JSON, slug ở bảng SEO riêng, chuyên mục theo site): [`examples/MultiSiteRepository.php`](./examples/MultiSiteRepository.php) + [`examples/ProjectTenantBootstrap.php`](./examples/ProjectTenantBootstrap.php).

## 6. Báo sửa từ site (tùy chọn)

Khi biên tập viên sửa bài do Base OS quản lý ngay trên site:

```php
use BaseOS\Bridge\Facades\BaseOSBridge;

BaseOSBridge::emitChanged($connectionId, remoteId: (string) $post->id, contentId: $post->baseos_content_id, changed: ['title']);
BaseOSBridge::emitDeleted($connectionId, (string) $post->id);
$conn = BaseOSBridge::connectionForTenant('site-a');   // multi-site
```

## 7. Cấu hình (`config/baseos-connect.php`)

| Khóa | Mặc định | |
|------|----------|--|
| `base_url` | `https://baseos.net` | `BASEOS_CONNECT_BASE_URL` hoặc `BASEOS_BASE_URL` |
| `routes.prefix` / `routes.domain` / `routes.middleware` | `baseos` / null / `['api']` | |
| `connections.driver` | `database` | `database` \| `config` \| class implement `ConnectionStore` |
| `repository` | `BASEOS_REPOSITORY` | bắt buộc (hoặc tự bind `ContentRepository`) |
| `schema_provider` / `tenant_resolver` / `tenant_bootstrap` | null | class |
| `tenancy.hosts` / `tenancy.from_host` / `tenancy.labels` | `[]` / false / `[]` | |
| `clock_tolerance` | 300 | giây |
| `idempotency.driver` / `ttl` | `database` / 7 ngày | `cache` dùng `cache_store` |
| `features` | upsert, set_state, trash, fetch, find, post.validate, site.schema, seo.meta | chỉ khai cái repository làm thật; config publish trước 0.2 → tự thêm `post.validate` |
| `expose_errors` | `APP_DEBUG` | trả message exception cho Base OS |

## 8. Wire protocol (tóm tắt)

- Header: `webhook-id`, `webhook-timestamp`, `webhook-signature: v1,base64(hmac_sha256(secret, "{id}.{ts}.{body}"))`, `baseos-connection`, `baseos-protocol`.
  **Khóa HMAC là nguyên chuỗi secret `whsec_…`** (không base64-decode) — giống `StandardWebhookSigner` của Base OS.
- Response: `{id, ok:true, result:{remote_id, url, admin_url, status, remote_hash, revision_seen}}` · lỗi `{id, ok:false, error:{code, message, retryable}}`.
- HTTP: 401 lỗi chữ ký/kết nối · 404 `command.unsupported` / `post.not_found` · 410 `command.expired` · 422 lỗi dữ liệu · 500 `internal` (retryable).
- Idempotency: kết quả thành công của `post.upsert` / `post.set_state` được lưu theo (kết nối, loại lệnh, `idempotency_key`); gửi lặp trả lại kết quả cũ (`replayed: true`). `post.find` cùng key trả `seen_key: true`. `post.validate` không bao giờ cache.
- Spec đầy đủ: `docs/cms-bridge/21-protocol-reference.md`, `docs/cms-bridge/25-MAPPING-AND-CUSTOM-PARAMS.md`.

## 9. Test

```bash
composer install && vendor/bin/phpunit     # Signer, BridgePost, Blocks, PostValidator — không cần boot Laravel
```

---

## English

**What:** a Base OS CMS Bridge receiver (Protocol 2.1/2.0) for any custom Laravel 10–13 app, single-site or multi-tenant.

**Install:** `composer require baseos/connect-laravel` → `php artisan vendor:publish --tag=baseos-connect-config` → `php artisan migrate` → set `APP_URL` (HTTPS) and `BASEOS_REPOSITORY`.

**Implement** `BaseOS\Bridge\Contracts\ContentRepository` (`upsert`, `setState`, `find`, `fetch`) — optionally extend `EloquentContentRepository`. Read input through `BridgePost` (`title()`, `bodyHtml()`, `bodyBlocks()`, `terms('category')`, `fields()`, `param('key')`, `tenant()`…), return `RemoteResult`. Throw `BridgeException` for expected errors. `upsert` must be idempotent per `contentId()`.

**Pair:** `php artisan baseos-connect:pair CODE [--site=] [--tenant=] [--receiver-url=]`, then `baseos-connect:hello` after structure changes, `baseos-connect:doctor` to diagnose, `baseos-connect:connections` to list.

**Multi-site:** one Base OS project/connection per website, pair each with `--tenant=`. Set `tenant_bootstrap` to a `TenantScope` (`enter($tenant)` / `leave()`) that switches your app's current-site context; it wraps every command and schema build. See `examples/`.

**Validate (0.2):** `post.validate` ("Kiểm tra với website") is answered out of the box with `{valid, issues[]}` — empty title/body, unknown post type, schema-required fields (`fields[].required` for the post type, fields with a `default` skipped) and required `params_spec`, plus warnings for unknown authors/terms/locales. Implement `BaseOS\Bridge\Contracts\ValidatesPosts::validate(BridgePost, ?string $remoteId): array` on your repository to add site-specific issues (`{code, path, message, blocking}`); never write in it. Not idempotency-cached; runs inside the tenant scope. Receivers without `post.validate` get a test draft whose content id starts with `baseos-test-` (then trashed) — `BridgePost::isTest()` lets you skip side effects for it.

**Schema:** implement `SchemaProvider` (or fill `config('baseos-connect.schema')`) using `SchemaBuilder` to report post types, taxonomies + terms (`ref` echoed back in `target.terms`), fields, authors, locales, tenant and `params_spec`.

**Signing note:** the HMAC key is the full `whsec_…` string (not base64-decoded), matching Base OS.

License: MIT.
