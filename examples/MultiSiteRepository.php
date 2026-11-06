<?php

/*
 * Example 2b — one codebase serving many websites, content stored as blocks.
 *
 * Assumed schema (generic — rename to match your app):
 *   sites:        id, key, domain, locale
 *   articles:     id, site_id, title, excerpt, body_blocks (json), cover_url, cover_alt, status,
 *                 published_at, baseos_content_id, baseos_revision, baseos_hash, timestamps
 *                 unique (site_id, baseos_content_id)
 *   seo_entries:  id, site_id, entity_type, entity_id, slug, meta_title, meta_description, canonical_url
 *                 unique (site_id, slug)                 ← slug lives here, not on articles
 *   categories:   id, site_id, name, slug                 ← categories are per site
 *   article_category: article_id, category_id
 *
 * Article / SeoEntry / Category use a global scope "where site_id = CurrentSite::id()" and fill
 * site_id on create. ProjectTenantBootstrap::enter() has already set the current site, so every
 * query below is automatically limited to the right website — we still pass site_id explicitly
 * where rows are created, as a belt-and-braces guard.
 *
 * Connection mapping in Base OS: body_format = blocks (so content.body_blocks is sent).
 *
 * config/baseos-connect.php:
 *   'repository'       => App\BaseOS\MultiSiteRepository::class,
 *   'tenant_bootstrap' => App\BaseOS\ProjectTenantBootstrap::class,
 *   'schema_provider'  => App\BaseOS\ExampleSchemaProvider::class,   // see schema-example.php
 */

namespace App\BaseOS;

use App\Models\Article;
use App\Models\Category;
use App\Models\SeoEntry;
use App\Support\CurrentSite;
use BaseOS\Connect\Data\BridgeContext;
use BaseOS\Connect\Data\BridgePost;
use BaseOS\Connect\Data\RemoteResult;
use BaseOS\Connect\Exceptions\BridgeException;
use BaseOS\Connect\Repository\EloquentContentRepository;
use BaseOS\Connect\Support\Blocks;

class MultiSiteRepository extends EloquentContentRepository
{
    public function upsert(BridgePost $post, ?string $remoteId): RemoteResult
    {
        $siteId = $this->siteId($post->context());

        // Params are operator-defined key/values on the connection (Base OS UI → Mapping → Params).
        // Read them for logic; do not write them blindly to the DB.
        $expected = $post->param('site_id');
        if ($expected !== null && (string) $expected !== (string) $siteId) {
            throw BridgeException::invalid("Param site_id={$expected} does not match this connection's site ({$siteId}).");
        }

        return $this->transaction(function () use ($post, $remoteId, $siteId) {
            $article = $remoteId !== null
                ? Article::find($remoteId)
                : Article::where('baseos_content_id', $post->contentId())->first();

            if ($remoteId !== null && ! $article) {
                throw BridgeException::notFound();
            }
            $article ??= new Article(['site_id' => $siteId]);

            // Blocks first; fall back to a single html block if the mapping still sends HTML.
            $blocks = $post->bodyBlocks() ?? [['type' => 'html', 'html' => $post->bodyHtml()]];

            $article->forceFill([
                'status' => $post->isPublished() ? 'published' : 'draft',
                'published_at' => $post->isPublished() ? ($article->published_at ?? now()) : $article->published_at,
                'baseos_content_id' => $post->contentId(),
                'baseos_revision' => $post->revision(),
                'baseos_hash' => $post->contentHash(),
            ]);
            $this->fillManaged($article, $post, [
                'title' => $post->title(),
                'excerpt' => $post->excerpt(),
                'body' => $blocks,
                'featured_media' => $post->featuredMedia()['url'] ?? null,
            ], columns: ['body' => 'body_blocks', 'featured_media' => 'cover_url']);

            // Slug + SEO in the separate table, unique per site.
            $seo = SeoEntry::firstOrNew(['entity_type' => 'article', 'entity_id' => $article->id], ['site_id' => $siteId]);
            if ($post->manages('slug') || ! $seo->exists) {
                $seo->slug = $this->uniqueSlug($post->slug() ?: $post->title(), fn (string $s) => SeoEntry::where('slug', $s)
                    ->when($seo->exists, fn ($q) => $q->whereKeyNot($seo->getKey()))
                    ->exists());
            }
            if ($post->manages('seo') || ! $seo->exists) {
                $seo->fill([
                    'meta_title' => $post->seo('title') ?: $post->title(),
                    'meta_description' => $post->seo('description'),
                    'canonical_url' => $post->seo('canonical_url'),
                ]);
            }
            $seo->save();

            // Per-site categories. Refs ("term:ID") come from ExampleSchemaProvider, which lists
            // only the current site's categories — so a ref from another site cannot match.
            if ($post->manages('taxonomy')) {
                $article->categories()->sync($this->resolveTermIds(
                    $post->terms('category'),
                    findById: fn (string $id) => Category::whereKey($id)->value('id'),
                    findBySlug: fn (string $slug) => Category::where('slug', $slug)->value('id'),
                    create: fn (string $name, string $slug) => Category::create(['site_id' => $siteId, 'name' => $name, 'slug' => $slug])->id,
                ));
            }

            return $this->snapshot($article->refresh(), $seo);
        });
    }

    public function setState(string $remoteId, string $state, BridgeContext $ctx): RemoteResult
    {
        $article = Article::find($remoteId) ?? throw BridgeException::notFound();

        $article->forceFill(['status' => match ($state) {
            'published' => 'published',
            'draft' => 'draft',
            'unpublished' => 'hidden',
            'trashed' => 'trashed',
        }])->save();

        return $this->snapshot($article, $this->seoFor($article));
    }

    public function find(string $contentId, BridgeContext $ctx): ?RemoteResult
    {
        $article = Article::where('baseos_content_id', $contentId)->first();

        return $article ? $this->snapshot($article, $this->seoFor($article)) : null;
    }

    public function fetch(string $remoteId, BridgeContext $ctx): ?RemoteResult
    {
        $article = Article::find($remoteId);
        if (! $article) {
            return null;
        }
        $seo = $this->seoFor($article);

        return $this->snapshot($article, $seo, [
            'title' => $article->title,
            'slug' => $seo?->slug,
            'seo' => ['title' => $seo?->meta_title, 'description' => $seo?->meta_description],
            'headings' => Blocks::headings((array) $article->body_blocks),
        ]);
    }

    private function siteId(BridgeContext $ctx): int|string
    {
        // Set by ProjectTenantBootstrap::enter(). Null means the connection has no tenant.
        return CurrentSite::id() ?? throw BridgeException::tenantUnknown(
            "Connection {$ctx->connectionId()} has no site. Pair with --tenant=… or set baseos-connect.tenancy.hosts."
        );
    }

    private function seoFor(Article $article): ?SeoEntry
    {
        return SeoEntry::where('entity_type', 'article')->where('entity_id', $article->id)->first();
    }

    /** @param  array<string, mixed>  $fields */
    private function snapshot(Article $article, ?SeoEntry $seo, array $fields = []): RemoteResult
    {
        $site = CurrentSite::model();
        $status = match ($article->status) {
            'published' => RemoteResult::PUBLISHED,
            'hidden' => RemoteResult::UNPUBLISHED,
            'trashed' => RemoteResult::TRASHED,
            default => RemoteResult::DRAFT,
        };

        return new RemoteResult(
            remoteId: (string) $article->id,
            // Build the public URL on the site's own domain, not APP_URL.
            url: $seo ? 'https://'.$site->domain.'/'.$seo->slug : null,
            adminUrl: url("/admin/sites/{$site->id}/articles/{$article->id}"),
            status: $status,
            remoteHash: $article->baseos_hash,
            revisionSeen: $article->baseos_revision,
            contentId: $article->baseos_content_id,
            modifiedAt: $article->updated_at?->toIso8601String(),
            fields: $fields,
        );
    }
}
