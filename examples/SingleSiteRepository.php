<?php

/*
 * Example 1 — one Laravel site, a classic `posts` table.
 *
 * Assumed schema (adapt names to your app):
 *   posts:       id, title, slug (unique), excerpt, body (longText), status ('draft'|'published'|'archived'),
 *                category_id (nullable), seo_title, seo_description, canonical_url,
 *                featured_image_url, featured_image_alt, published_at,
 *                baseos_content_id (nullable, unique), baseos_revision (int), baseos_hash, timestamps, deleted_at
 *   categories:  id, name, slug
 *   tags:        id, name, slug        post_tag: post_id, tag_id
 *
 * config/baseos-connect.php:  'repository' => App\BaseOS\SingleSiteRepository::class
 */

namespace App\BaseOS;

use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use BaseOS\Connect\Contracts\ValidatesPosts;
use BaseOS\Connect\Data\BridgeContext;
use BaseOS\Connect\Data\BridgePost;
use BaseOS\Connect\Data\RemoteResult;
use BaseOS\Connect\Exceptions\BridgeException;
use BaseOS\Connect\Repository\EloquentContentRepository;

class SingleSiteRepository extends EloquentContentRepository implements ValidatesPosts
{
    /**
     * `post.validate` — the "Kiểm tra với website" button. Read only: never write here.
     * Built-in checks (title/body, schema-required fields & params, unknown terms…) already ran;
     * add only what your app knows.
     */
    public function validate(BridgePost $post, ?string $remoteId): array
    {
        $issues = [];

        if ($remoteId !== null && ! Post::withTrashed()->whereKey($remoteId)->exists()) {
            $issues[] = ['code' => 'post.not_found', 'path' => 'remote_id', 'message' => "Bài #{$remoteId} không còn trên website."];
        }

        $slug = $post->slug();
        if ($slug !== '' && $post->manages('slug') && Post::withTrashed()
            ->where('slug', $slug)
            ->where(fn ($q) => $q->whereNull('baseos_content_id')->orWhere('baseos_content_id', '!=', $post->contentId()))
            ->when($remoteId !== null, fn ($q) => $q->whereKeyNot($remoteId))
            ->exists()) {
            $issues[] = ['code' => 'slug.conflict', 'path' => 'content.slug', 'blocking' => false,
                'message' => "Đường dẫn \"{$slug}\" đã có bài khác dùng — sẽ tự thêm hậu tố."];
        }

        if ($post->isPublished() && $post->featuredMedia() === null) {
            $issues[] = ['code' => 'media.featured_required', 'path' => 'content.featured_media',
                'message' => 'Website này yêu cầu ảnh đại diện khi đăng công khai.'];
        }

        return $issues;
    }

    public function upsert(BridgePost $post, ?string $remoteId): RemoteResult
    {
        return $this->transaction(function () use ($post, $remoteId) {
            // 1. Locate: remote id first, then content id (makes retries idempotent).
            $model = $remoteId !== null
                ? Post::withTrashed()->find($remoteId)
                : Post::withTrashed()->where('baseos_content_id', $post->contentId())->first();

            if ($remoteId !== null && ! $model) {
                throw BridgeException::notFound("Post {$remoteId} no longer exists.");
            }
            $model ??= new Post;
            if ($model->trashed()) {
                $model->restore();
            }

            // 2. Slug: keep it unique, but never rename a slug to itself + "-2".
            $slug = $this->uniqueSlug($post->slug() ?: $post->title(), fn (string $s) => Post::withTrashed()
                ->where('slug', $s)
                ->when($model->exists, fn ($q) => $q->whereKeyNot($model->getKey()))
                ->exists());

            // 3. Bookkeeping + state.
            $model->forceFill([
                'status' => $post->isPublished() ? 'published' : 'draft',
                'published_at' => $post->isPublished() ? ($model->published_at ?? $post->publishedAt() ?? now()) : $model->published_at,
                'baseos_content_id' => $post->contentId(),
                'baseos_revision' => $post->revision(),
                'baseos_hash' => $post->contentHash(),
            ]);

            // 4. Fields Base OS manages (fields_mask respected by fillManaged, which saves).
            $this->fillManaged($model, $post, [
                'title' => $post->title(),
                'slug' => $slug,
                'excerpt' => $post->excerpt(),
                'body' => $post->bodyHtml(),
                'seo_title' => $post->seo('title'),
                'seo_description' => $post->seo('description'),
                'canonical_url' => $post->seo('canonical_url'),
                'featured_image_url' => $post->featuredMedia()['url'] ?? null,
                'featured_image_alt' => $post->featuredMedia()['alt'] ?? null,
            ]);

            // 5. Terms: refs come from your schema ("term:12"), new names only when create=true.
            $categoryIds = $this->resolveTermIds(
                $post->terms('category'),
                findById: fn (string $id) => Category::whereKey($id)->value('id'),
                findBySlug: fn (string $slug) => Category::where('slug', $slug)->value('id'),
                create: fn (string $name, string $slug) => Category::create(['name' => $name, 'slug' => $slug])->id,
            );
            if ($post->manages('taxonomy') || ! $model->exists) {
                $model->category_id = $categoryIds[0] ?? $model->category_id;
            }
            $model->save();

            if ($post->manages('taxonomy')) {
                $model->tags()->sync($this->resolveTermIds(
                    $post->terms('post_tag'),
                    findById: fn (string $id) => Tag::whereKey($id)->value('id'),
                    findBySlug: fn (string $slug) => Tag::where('slug', $slug)->value('id'),
                    create: fn (string $name, string $slug) => Tag::create(['name' => $name, 'slug' => $slug])->id,
                ));
            }

            // 6. Optional: custom fields mapped in Base OS (target.fields) / params.
            //    e.g. $model->reading_time = $post->field('meta:reading_time');
            //         if ($post->param('channel') === 'news') { ... }

            // 7. Side effects — skip for the "Kiểm tra với website" test draft (trashed right after).
            if (! $post->isTest()) {
                // Cache::tags('posts')->flush(); PingSitemap::dispatch(); …
            }

            return $this->snapshot($model->refresh());
        });
    }

    public function setState(string $remoteId, string $state, BridgeContext $ctx): RemoteResult
    {
        $model = Post::withTrashed()->find($remoteId) ?? throw BridgeException::notFound();
        if ($state !== 'trashed' && $model->trashed()) {
            $model->restore();
        }

        match ($state) {
            'published' => $model->forceFill(['status' => 'published', 'published_at' => $model->published_at ?? now()])->save(),
            'draft' => $model->forceFill(['status' => 'draft'])->save(),
            'unpublished' => $model->forceFill(['status' => 'archived'])->save(),
            'trashed' => $model->delete(),
        };

        return $this->snapshot($model->refresh());
    }

    public function find(string $contentId, BridgeContext $ctx): ?RemoteResult
    {
        $model = Post::withTrashed()->where('baseos_content_id', $contentId)->first();

        return $model ? $this->snapshot($model) : null;
    }

    public function fetch(string $remoteId, BridgeContext $ctx): ?RemoteResult
    {
        $model = Post::withTrashed()->find($remoteId);

        return $model ? $this->snapshot($model, withFields: true) : null;
    }

    private function snapshot(Post $model, bool $withFields = false): RemoteResult
    {
        $status = match (true) {
            $model->trashed() => RemoteResult::TRASHED,
            $model->status === 'published' => RemoteResult::PUBLISHED,
            $model->status === 'archived' => RemoteResult::UNPUBLISHED,
            default => RemoteResult::DRAFT,
        };

        return new RemoteResult(
            remoteId: (string) $model->getKey(),
            url: route('posts.show', $model->slug),
            adminUrl: url("/admin/posts/{$model->getKey()}/edit"),
            status: $status,
            remoteHash: $model->baseos_hash,
            revisionSeen: $model->baseos_revision,
            contentId: $model->baseos_content_id,
            modifiedAt: $model->updated_at?->toIso8601String(),
            fields: $withFields ? [
                'title' => $model->title,
                'slug' => $model->slug,
                'status' => $status,
                'seo' => ['title' => $model->seo_title, 'description' => $model->seo_description],
            ] : [],
        );
    }
}
