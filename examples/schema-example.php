<?php

/*
 * Example 3 — site schema (sent with `hello` and in reply to `site.schema` / "Quét cấu trúc").
 *
 * Reported terms let Base OS auto-map categories ("Ẩm thực" ↔ term:12) and send back
 * `target.terms.category = [{ref: "term:12"}]`. params_spec declares the custom params the
 * operator may fill per connection; required ones block publishing until set.
 *
 * Wire shape produced (docs/cms-bridge/25 §4.3):
 * {
 *   "schema_version": 1,
 *   "cms": {"name": "laravel", "version": "11.x", "php": "8.3", "receiver": "baseos/connect-laravel"},
 *   "content_formats": ["html", "blocks"],
 *   "post_types": [{"ref": "article", "label": "Article", "taxonomies": ["category"]}],
 *   "taxonomies": [{"ref": "category", "label": "Category", "hierarchical": true, "role": "category",
 *                   "terms": [{"ref": "term:12", "name": "Ẩm thực", "slug": "am-thuc", "parent": null}]}],
 *   "fields": [{"ref": "meta:reading_time", "label": "Reading time", "type": "number", "required": false}],
 *   "authors": [{"ref": "user:7", "name": "Editor"}],
 *   "locales": ["vi", "en"],
 *   "tenant": {"id": "site-a", "label": "site-a.com"},
 *   "params_spec": [{"key": "channel", "label": "Channel", "type": "string", "enum": ["blog", "news"], "default": "blog"}],
 *   "defaults": {"post_type": "article", "category_taxonomy": "category", "tag_taxonomy": "post_tag", "body_format": "blocks"}
 * }
 *
 * config/baseos-connect.php:  'schema_provider' => App\BaseOS\ExampleSchemaProvider::class
 */

namespace App\BaseOS;

use App\Models\Category;
use App\Models\User;
use App\Support\CurrentSite;
use BaseOS\Connect\Contracts\SchemaProvider;
use BaseOS\Connect\Data\BridgeContext;
use BaseOS\Connect\Schema\SchemaBuilder;

class ExampleSchemaProvider implements SchemaProvider
{
    public function schema(BridgeContext $ctx): array
    {
        // The tenant scope is active here: Category::query() only returns the current site's rows.
        $categories = Category::query()->orderBy('name')->limit(1000)->get()->map(fn (Category $c) => [
            'ref' => 'term:'.$c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'parent' => $c->parent_id ? 'term:'.$c->parent_id : null,
        ]);

        $builder = SchemaBuilder::base(app()->version())
            ->formats(['html', 'blocks'])
            ->postType('article', 'Article', ['category'])
            ->taxonomy('category', 'Category', $categories, hierarchical: true, role: 'category')
            ->field('meta:reading_time', 'Reading time (min)', 'number')
            ->locales(['vi', 'en'])
            ->param('channel', 'Channel', 'string', enum: ['blog', 'news'], default: 'blog')
            ->param('site_id', 'Website ID (safety check)', 'number', help: 'Must equal this site id; mismatches are rejected.')
            ->defaults([
                'post_type' => 'article',
                'category_taxonomy' => 'category',
                'tag_taxonomy' => 'post_tag',
                'body_format' => 'blocks',
            ]);

        foreach (User::query()->where('is_editor', true)->limit(200)->get() as $user) {
            $builder->author('user:'.$user->id, $user->name);
        }

        $site = CurrentSite::model();

        return $builder
            ->tenant($ctx->tenant, $site?->domain ?? $ctx->connection->siteHost())
            ->toArray();
    }
}
