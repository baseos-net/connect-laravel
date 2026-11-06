<?php

namespace BaseOS\Connect\Schema;

/**
 * Fluent builder for the `site.schema` payload (docs/cms-bridge/25 §4.3).
 *
 *   return SchemaBuilder::base(app()->version())
 *       ->postType('post', 'Bài viết', ['category', 'post_tag'])
 *       ->taxonomy('category', 'Chuyên mục', $terms, hierarchical: true, role: 'category')
 *       ->field('meta:rating', 'Số sao', 'number', required: false)
 *       ->param('channel', 'Kênh', 'string', enum: ['blog', 'news'], default: 'blog')
 *       ->tenant($ctx->tenant, 'vitravel.dev')
 *       ->toArray();
 */
final class SchemaBuilder
{
    /** @var array<string, mixed> */
    private array $schema;

    private function __construct()
    {
        $this->schema = [
            'schema_version' => 1,
            'cms' => ['name' => 'laravel'],
            'content_formats' => ['html'],
            'post_types' => [],
            'taxonomies' => [],
            'fields' => [],
            'authors' => [],
            'locales' => [],
            'tenant' => null,
            'params_spec' => [],
            'defaults' => [],
        ];
    }

    public static function base(?string $laravelVersion = null): self
    {
        $b = new self;
        $b->schema['cms'] = array_filter([
            'name' => 'laravel',
            'version' => $laravelVersion,
            'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            'receiver' => 'baseos/connect-laravel',
        ]);

        return $b;
    }

    /** @param  array<string, mixed>  $partial */
    public function merge(array $partial): self
    {
        foreach ($partial as $key => $value) {
            if ($value === null || $value === []) {
                continue;
            }
            $this->schema[$key] = is_array($value) && is_array($this->schema[$key] ?? null) && ! array_is_list($value)
                ? array_replace($this->schema[$key], $value)
                : $value;
        }

        return $this;
    }

    /** @param  list<string>  $formats  html | blocks */
    public function formats(array $formats): self
    {
        $this->schema['content_formats'] = array_values($formats);

        return $this;
    }

    /** @param  list<string>  $taxonomies */
    public function postType(string $ref, ?string $label = null, array $taxonomies = []): self
    {
        $this->schema['post_types'][] = ['ref' => $ref, 'label' => $label ?? $ref, 'taxonomies' => $taxonomies];

        return $this;
    }

    /**
     * @param  iterable<array{ref: string, name: string, slug?: string|null, parent?: string|null}>  $terms
     * @param  'category'|'tag'|null  $role
     */
    public function taxonomy(string $ref, ?string $label = null, iterable $terms = [], bool $hierarchical = false, ?string $role = null): self
    {
        $list = [];
        foreach ($terms as $t) {
            $list[] = [
                'ref' => (string) $t['ref'],
                'name' => (string) $t['name'],
                'slug' => $t['slug'] ?? null,
                'parent' => $t['parent'] ?? null,
            ];
        }
        $this->schema['taxonomies'][] = array_filter([
            'ref' => $ref,
            'label' => $label ?? $ref,
            'hierarchical' => $hierarchical,
            'role' => $role,
            'terms' => $list,
        ], fn ($v) => $v !== null);

        return $this;
    }

    /**
     * @param  string  $type  string | text | number | bool | date | ref | json | select
     * @param  list<string>  $postTypes
     * @param  list<mixed>|null  $options
     */
    public function field(string $ref, ?string $label = null, string $type = 'string', bool $required = false, array $postTypes = [], ?array $options = null, ?string $help = null, mixed $default = null, bool $hasDefault = false): self
    {
        $row = array_filter([
            'ref' => $ref,
            'label' => $label ?? $ref,
            'type' => $type,
            'required' => $required,
            'post_types' => $postTypes,
            'options' => $options,
            'help' => $help,
        ], fn ($v) => $v !== null);
        if ($hasDefault || $default !== null) {
            $row['default'] = $default;
        }
        $this->schema['fields'][] = $row;

        return $this;
    }

    public function author(string $ref, string $name): self
    {
        $this->schema['authors'][] = ['ref' => $ref, 'name' => $name];

        return $this;
    }

    /** @param  list<string>  $locales */
    public function locales(array $locales): self
    {
        $this->schema['locales'] = array_values($locales);

        return $this;
    }

    public function tenant(?string $id, ?string $label = null): self
    {
        $this->schema['tenant'] = $id !== null && $id !== '' ? ['id' => $id, 'label' => $label ?? $id] : null;

        return $this;
    }

    /**
     * @param  string  $type  string | number | bool | json
     * @param  list<mixed>|null  $enum
     */
    public function param(string $key, ?string $label = null, string $type = 'string', bool $required = false, ?array $enum = null, mixed $default = null, ?string $help = null): self
    {
        $this->schema['params_spec'][] = array_filter([
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => $required,
            'enum' => $enum,
            'default' => $default,
            'help' => $help,
        ], fn ($v) => $v !== null);

        return $this;
    }

    /** @param  array{post_type?: string, author?: string, category_taxonomy?: string, tag_taxonomy?: string, body_format?: string, default_category?: string, locale?: string}  $defaults */
    public function defaults(array $defaults): self
    {
        $this->schema['defaults'] = $defaults + $this->schema['defaults'];

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->schema;
    }
}
