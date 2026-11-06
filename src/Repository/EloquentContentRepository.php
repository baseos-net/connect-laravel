<?php

namespace BaseOS\Connect\Repository;

use BaseOS\Connect\Contracts\ContentRepository;
use BaseOS\Connect\Data\BridgeContext;
use BaseOS\Connect\Data\BridgePost;
use BaseOS\Connect\Data\RemoteResult;
use BaseOS\Connect\Exceptions\BridgeException;
use BaseOS\Connect\Support\Blocks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Optional base class with helpers that are common to Eloquent-backed sites.
 * It does not assume any model, table or column — your subclass decides.
 *
 * Term refs: Base OS echoes back the refs your SchemaProvider reported (e.g. "term:12").
 * Use ref()/parseRef() with the same prefix in both places.
 */
abstract class EloquentContentRepository implements ContentRepository
{
    /** Prefix used in term refs ("term:12"). */
    protected string $termRefPrefix = 'term';

    /**
     * Turn `$post->terms($taxonomy)` into ids of your term rows.
     *
     *   $ids = $this->resolveTermIds($post->terms('category'),
     *       findById:   fn (string $id) => Category::whereKey($id)->value('id'),
     *       findBySlug: fn (string $slug, string $name) => Category::where('slug', $slug)->value('id'),
     *       create:     fn (string $name, string $slug) => Category::create(compact('name', 'slug'))->id,
     *   );
     *
     * - {ref}                 → findById(parsed id); unknown refs are skipped
     * - {name, slug}          → findBySlug(slug, name)
     * - {name, create: true}  → create(name, slug) when not found and $create is given
     *
     * @param  list<array<string, mixed>>  $terms
     * @param  callable(string): (int|string|null)  $findById
     * @param  callable(string, string): (int|string|null)  $findBySlug
     * @param  (callable(string, string): (int|string|null))|null  $create
     * @return list<int|string>
     */
    protected function resolveTermIds(array $terms, callable $findById, callable $findBySlug, ?callable $create = null): array
    {
        $ids = [];
        foreach ($terms as $term) {
            $id = null;
            if (! empty($term['ref'])) {
                $parsed = $this->parseRef((string) $term['ref']);
                $id = $parsed !== null ? $findById($parsed) : null;
            } elseif (! empty($term['name']) || ! empty($term['slug'])) {
                $name = trim((string) ($term['name'] ?? $term['slug']));
                $slug = (string) ($term['slug'] ?? '') ?: Str::slug($name);
                $id = $findBySlug($slug, $name);
                if ($id === null && ($term['create'] ?? false) && $create !== null) {
                    $id = $create($name, $slug);
                }
            }
            if ($id !== null && $id !== '' && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** "term:12" → "12" (null when the prefix differs). Bare values are returned as-is. */
    protected function parseRef(string $ref, ?string $prefix = null): ?string
    {
        $prefix ??= $this->termRefPrefix;
        if (! str_contains($ref, ':')) {
            return $ref;
        }
        [$p, $id] = explode(':', $ref, 2);

        return $p === $prefix && $id !== '' ? $id : null;
    }

    protected function ref(int|string $id, ?string $prefix = null): string
    {
        return ($prefix ?? $this->termRefPrefix).':'.$id;
    }

    /**
     * HTML to store: body_html, or blocks rendered when only blocks are sent.
     */
    protected function bodyHtml(BridgePost $post): string
    {
        return $post->bodyHtml();
    }

    /** Blocks to store: body_blocks, or null when the mapping sends HTML only. */
    protected function bodyBlocks(BridgePost $post): ?array
    {
        return $post->bodyBlocks();
    }

    /** Map Base OS states onto a "is published" flag / status column. */
    protected function isPublishedState(string $state): bool
    {
        return $state === RemoteResult::PUBLISHED;
    }

    /**
     * Fill only what Base OS manages (respects fields_mask) and save.
     *
     * @param  array<string, mixed>  $attributes  keys = contract field names (title, slug, excerpt, body, seo…)
     * @param  array<string, string>  $columns    contract field => model attribute
     */
    protected function fillManaged(Model $model, BridgePost $post, array $attributes, array $columns = []): Model
    {
        foreach ($attributes as $field => $value) {
            if ($post->manages($field) || ! $model->exists) {
                $model->setAttribute($columns[$field] ?? $field, $value);
            }
        }
        $model->save();

        return $model;
    }

    /** Ensure a slug is unique using $exists(slug): bool; appends -2, -3… */
    protected function uniqueSlug(string $slug, callable $exists, int $max = 50): string
    {
        $base = Str::slug($slug) ?: 'post';
        $candidate = $base;
        for ($i = 2; $exists($candidate) && $i <= $max + 1; $i++) {
            $candidate = $base.'-'.$i;
        }
        if ($exists($candidate)) {
            throw BridgeException::slugTaken($base);
        }

        return $candidate;
    }

    /** @template T @param callable(): T $callback @return T */
    protected function transaction(callable $callback, ?string $connection = null): mixed
    {
        return DB::connection($connection)->transaction($callback);
    }

    protected function result(int|string $remoteId, ?string $url, ?string $adminUrl, string $status, ?BridgePost $post = null, ?string $remoteHash = null, ?int $revision = null, ?string $contentId = null, mixed $modifiedAt = null): RemoteResult
    {
        return new RemoteResult(
            remoteId: (string) $remoteId,
            url: $url,
            adminUrl: $adminUrl,
            status: $status,
            remoteHash: $remoteHash ?? $post?->contentHash(),
            revisionSeen: $revision ?? $post?->revision(),
            contentId: $contentId ?? $post?->contentId(),
            modifiedAt: $modifiedAt instanceof \DateTimeInterface ? $modifiedAt->format(DATE_ATOM) : ($modifiedAt !== null ? (string) $modifiedAt : null),
        );
    }

    /** @param  list<array<string, mixed>>  $blocks */
    protected function blocksToHtml(array $blocks): string
    {
        return Blocks::toHtml($blocks);
    }

    public function find(string $contentId, BridgeContext $ctx): ?RemoteResult
    {
        return null;
    }
}
