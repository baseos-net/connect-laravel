<?php

namespace BaseOS\Connect\Data;

use BaseOS\Connect\Bridge;
use BaseOS\Connect\Support\Blocks;
use Illuminate\Support\Str;

/**
 * The `data` of a `post.upsert` command (Protocol 2.1, compatible with 2.0).
 *
 *   content  — content contract v2 (same for every site) (+ body_blocks when body_format=blocks)
 *   target   — per-connection mapping resolved by Base OS: post_type, author, locale, terms, fields…
 *   params   — custom key/values configured on the connection (site_id, channel…), already typed
 */
final class BridgePost
{
    /** @var array<string, mixed> */
    private array $content;

    /** @var array<string, mixed> */
    private array $target;

    /** @var array<string, mixed> */
    private array $params;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data, private BridgeContext $context)
    {
        $this->content = self::arr($data['content'] ?? []);
        $this->target = self::arr($data['target'] ?? []);
        $this->params = self::arr($data['params'] ?? []);
    }

    // ── Envelope data ────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    public function remoteId(): ?string
    {
        $id = $this->data['remote_id'] ?? null;

        return $id === null || $id === '' ? null : (string) $id;
    }

    /** Desired state: `published` | `draft`. */
    public function state(): string
    {
        return (string) ($this->data['state'] ?? 'draft');
    }

    public function isPublished(): bool
    {
        return $this->state() === 'published';
    }

    public function mappingVersion(): ?int
    {
        return isset($this->data['mapping_version']) ? (int) $this->data['mapping_version'] : null;
    }

    /** @return list<string>|null Fields Base OS manages (null = all). */
    public function fieldsMask(): ?array
    {
        $mask = $this->data['fields_mask'] ?? null;

        return is_array($mask) ? array_values(array_map('strval', $mask)) : null;
    }

    /** Should Base OS overwrite this field? (true when no mask is sent). */
    public function manages(string $field): bool
    {
        $mask = $this->fieldsMask();

        return $mask === null || in_array($field, $mask, true);
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return self::arr($this->data['options'] ?? []);
    }

    // ── Content contract ─────────────────────────────────────────────────

    /** Whole contract, or one value by dot path (`seo.title`). */
    public function content(?string $path = null, mixed $default = null): mixed
    {
        return $path === null ? $this->content : data_get($this->content, $path, $default);
    }

    public function contentId(): ?string
    {
        $id = $this->content['content_id'] ?? null;

        return $id === null ? null : (string) $id;
    }

    /**
     * Test draft from the "Kiểm tra với website" fallback (content id `baseos-test-…`): store it
     * normally, but you may skip side effects (cache purge, notifications, sitemap ping).
     * Base OS moves it to `trashed` right after.
     */
    public function isTest(): bool
    {
        return str_starts_with((string) $this->contentId(), Bridge::TEST_CONTENT_PREFIX);
    }

    public function revision(): ?int
    {
        return isset($this->content['revision']) ? (int) $this->content['revision'] : null;
    }

    public function contentHash(): ?string
    {
        return isset($this->content['content_hash']) ? (string) $this->content['content_hash'] : null;
    }

    public function contentType(): ?string
    {
        return isset($this->content['content_type']) ? (string) $this->content['content_type'] : null;
    }

    public function title(): string
    {
        return (string) ($this->content['title'] ?? '');
    }

    public function slug(): string
    {
        return (string) ($this->content['slug'] ?? '');
    }

    public function excerpt(): ?string
    {
        $v = $this->content['excerpt'] ?? null;

        return $v === null ? null : (string) $v;
    }

    /**
     * Sanitized HTML from Base OS. When only blocks were sent, they are rendered to HTML.
     */
    public function bodyHtml(): string
    {
        $html = (string) ($this->content['body_html'] ?? '');
        if ($html === '' && ($blocks = $this->bodyBlocks()) !== null) {
            return Blocks::toHtml($blocks);
        }

        return $html;
    }

    /**
     * `content.body_blocks` — present when the connection mapping uses body_format=blocks.
     *
     * @return list<array<string, mixed>>|null
     */
    public function bodyBlocks(): ?array
    {
        $blocks = $this->content['body_blocks'] ?? null;

        return is_array($blocks) ? array_values($blocks) : null;
    }

    /** `target.body_format` (`html` | `blocks`). */
    public function bodyFormat(): string
    {
        return (string) ($this->target['body_format'] ?? ($this->bodyBlocks() !== null ? 'blocks' : 'html'));
    }

    /** `target.locale` (mapping override) → `content.locale`. */
    public function locale(): ?string
    {
        $v = $this->target['locale'] ?? $this->content['locale'] ?? null;

        return $v === null ? null : (string) $v;
    }

    /** @return array{title?: string, description?: string, canonical_url?: string, target_keyword?: string, robots?: mixed, schema?: mixed} */
    public function seo(?string $key = null, mixed $default = null): mixed
    {
        $seo = self::arr($this->content['seo'] ?? []);

        return $key === null ? $seo : ($seo[$key] ?? $default);
    }

    /** @return array{url?: string, alt?: string, width?: int, height?: int, sha256?: string}|null */
    public function featuredMedia(): ?array
    {
        $m = $this->content['featured_media'] ?? null;

        return is_array($m) && ! empty($m['url']) ? $m : null;
    }

    /** @return list<array<string, mixed>> */
    public function inlineMedia(): array
    {
        return array_values(self::arr($this->content['inline_media'] ?? []));
    }

    public function publishedAt(): ?string
    {
        $v = $this->content['published_at'] ?? null;

        return $v === null ? null : (string) $v;
    }

    /** Raw contract taxonomy: `{category: string|null, tags: list<string>}`. */
    public function taxonomy(): array
    {
        return self::arr($this->content['taxonomy'] ?? []);
    }

    // ── Target (mapping resolved by Base OS) ─────────────────────────────

    public function target(?string $path = null, mixed $default = null): mixed
    {
        return $path === null ? $this->target : data_get($this->target, $path, $default);
    }

    public function postType(?string $default = null): ?string
    {
        $v = $this->target['post_type'] ?? null;

        return $v === null || $v === '' ? $default : (string) $v;
    }

    /** Author ref (`user:7`) or a display name. */
    public function author(): ?string
    {
        $v = $this->target['author'] ?? null;

        return $v === null || $v === '' ? null : (string) $v;
    }

    public function mediaPolicy(): string
    {
        return (string) ($this->target['media_policy'] ?? 'sideload');
    }

    /**
     * Terms to attach for a taxonomy: `[{ref: "term:12"}]` or `[{name, slug, create: true}]`.
     *
     * Protocol 2.0 fallback (no `target.terms`): `category` / `post_tag` are derived
     * from `content.taxonomy` as `{name, slug, create: true}`.
     *
     * @return list<array{ref?: string, name?: string, slug?: string, create?: bool}>
     */
    public function terms(string $taxonomy): array
    {
        $terms = $this->target['terms'] ?? null;
        if (is_array($terms)) {
            return array_values(array_filter(self::arr($terms[$taxonomy] ?? []), 'is_array'));
        }

        $tax = $this->taxonomy();
        $names = match ($taxonomy) {
            'category' => array_filter([(string) ($tax['category'] ?? '')]),
            'post_tag', 'tag', 'tags' => array_map('strval', self::arr($tax['tags'] ?? [])),
            default => [],
        };

        return array_values(array_map(fn (string $n) => [
            'name' => $n,
            'slug' => Str::slug($n),
            'create' => true,
        ], array_filter($names, fn ($n) => trim($n) !== '')));
    }

    /** @return list<string> Taxonomies present in `target.terms`. */
    public function taxonomies(): array
    {
        $terms = $this->target['terms'] ?? null;

        return is_array($terms) ? array_map('strval', array_keys($terms)) : [];
    }

    /** `target.fields` — custom fields keyed by site field name (e.g. `meta:rating`). @return array<string, mixed> */
    public function fields(): array
    {
        return self::arr($this->target['fields'] ?? []);
    }

    public function field(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $f = $this->fields()) ? $f[$key] : $default;
    }

    // ── Params & context ─────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function params(): array
    {
        return $this->params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->params) ? $this->params[$key] : $default;
    }

    public function tenant(): ?string
    {
        return $this->context->tenant;
    }

    public function context(): BridgeContext
    {
        return $this->context;
    }

    /** @return array<mixed> */
    private static function arr(mixed $v): array
    {
        if (is_object($v)) {
            $v = (array) $v;
        }

        return is_array($v) ? $v : [];
    }
}
