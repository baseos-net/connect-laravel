<?php

namespace BaseOS\Connect\Validation;

use BaseOS\Connect\Data\BridgePost;

/**
 * Built-in checks for `post.validate`, driven by the receiver's own `site.schema`.
 * Pure (no container, no I/O) — the dispatcher supplies the schema.
 *
 * Issue shape (Protocol 2.1): {code, path: string|null, message, blocking}.
 * `field.required` / `param.required` mirror Base OS MappingResolver so both sides agree.
 */
final class PostValidator
{
    public const STATES = ['published', 'draft'];

    /** @return array{code: string, path: string|null, message: string, blocking: bool} */
    public static function issue(string $code, ?string $path, string $message, bool $blocking = true): array
    {
        return ['code' => $code, 'path' => $path, 'message' => $message, 'blocking' => $blocking];
    }

    /**
     * @param  array<string, mixed>  $schema  result of SchemaProvider::schema() for the current tenant
     * @return list<array{code: string, path: string|null, message: string, blocking: bool}>
     */
    public static function check(BridgePost $post, array $schema = []): array
    {
        $issues = [];

        if (! in_array($post->state(), self::STATES, true)) {
            $issues[] = self::issue('state.invalid', 'state', "Trạng thái không hợp lệ: {$post->state()} (chỉ nhận published | draft).");
        }
        if ($post->manages('title') && trim(strip_tags($post->title())) === '') {
            $issues[] = self::issue('content.title_required', 'content.title', 'Bài chưa có tiêu đề.');
        }
        if ($post->manages('body_html') && trim($post->bodyHtml()) === '') {
            $issues[] = self::issue('content.body_required', 'content.body_html', 'Nội dung bài trống.');
        }

        $postType = $post->postType(self::str($schema['defaults']['post_type'] ?? null));
        self::checkPostType($postType, $schema, $issues);
        self::checkAuthor($post, $schema, $issues);
        if ($post->manages('taxonomy')) {
            self::checkTerms($post, $schema, $issues);
        }
        if ($post->manages('fields')) {
            self::checkRequiredFields($post, $postType, $schema, $issues);
        }
        self::checkRequiredParams($post, $schema, $issues);
        self::checkLocale($post, $schema, $issues);

        return $issues;
    }

    /**
     * Normalizes issues (e.g. from ValidatesPosts), later entries with the same code+path win.
     *
     * @param  iterable<mixed>  $issues
     * @return list<array{code: string, path: string|null, message: string, blocking: bool}>
     */
    public static function normalize(iterable $issues): array
    {
        $out = [];
        foreach ($issues as $issue) {
            if (is_string($issue) && $issue !== '') {
                $issue = ['code' => 'post.invalid', 'message' => $issue];
            }
            if (! is_array($issue) || empty($issue['code'])) {
                continue;
            }
            $path = isset($issue['path']) && $issue['path'] !== '' ? (string) $issue['path'] : null;
            $row = self::issue(
                (string) $issue['code'],
                $path,
                (string) ($issue['message'] ?? $issue['code']),
                (bool) ($issue['blocking'] ?? true),
            );
            $out[$row['code'].'|'.$path] = $row;
        }

        return array_values($out);
    }

    /**
     * @param  iterable<mixed>  $issues
     * @return array{valid: bool, issues: list<array{code: string, path: string|null, message: string, blocking: bool}>}
     */
    public static function result(iterable $issues): array
    {
        $issues = self::normalize($issues);

        return [
            'valid' => ! in_array(true, array_column($issues, 'blocking'), true),
            'issues' => $issues,
        ];
    }

    /** @param  list<array<string, mixed>>  $issues */
    private static function checkPostType(?string $postType, array $schema, array &$issues): void
    {
        $known = self::refs($schema['post_types'] ?? []);
        if ($postType !== null && $known !== [] && ! in_array($postType, $known, true)) {
            $issues[] = self::issue('post_type.unknown', 'target.post_type', "Website không có loại bài \"{$postType}\" — quét lại cấu trúc site và chọn loại bài khác.");
        }
    }

    /** @param  list<array<string, mixed>>  $issues */
    private static function checkAuthor(BridgePost $post, array $schema, array &$issues): void
    {
        $author = $post->author();
        $known = self::refs($schema['authors'] ?? []);
        if ($author === null || ! $post->manages('author') || $known === [] || in_array($author, $known, true)) {
            return;
        }
        $issues[] = self::issue('author.unknown', 'target.author', "Không tìm thấy tác giả {$author} trên website — bài sẽ dùng tác giả mặc định.", false);
    }

    /** @param  list<array<string, mixed>>  $issues */
    private static function checkTerms(BridgePost $post, array $schema, array &$issues): void
    {
        $taxonomies = [];
        foreach ((array) ($schema['taxonomies'] ?? []) as $tax) {
            if (is_array($tax) && isset($tax['ref'])) {
                $taxonomies[(string) $tax['ref']] = is_array($tax['terms'] ?? null) ? $tax['terms'] : [];
            }
        }
        if ($taxonomies === []) {
            return;
        }

        foreach ($post->taxonomies() as $taxonomy) {
            $path = 'target.terms.'.$taxonomy;
            if (! array_key_exists($taxonomy, $taxonomies)) {
                $issues[] = self::issue('taxonomy.unknown', $path, "Website không có taxonomy \"{$taxonomy}\" — chuyên mục/thẻ sẽ không được gán.", false);

                continue;
            }
            $terms = $taxonomies[$taxonomy];
            if ($terms === []) {
                continue; // schema does not list terms → nothing to compare against
            }
            $refs = self::refs($terms);
            $slugs = array_map(fn ($t) => mb_strtolower((string) ($t['slug'] ?? '')), array_filter($terms, 'is_array'));
            $names = array_map(fn ($t) => mb_strtolower(trim((string) ($t['name'] ?? ''))), array_filter($terms, 'is_array'));

            foreach ($post->terms($taxonomy) as $i => $item) {
                $itemPath = $path.'.'.$i;
                if (! empty($item['ref'])) {
                    if (! in_array((string) $item['ref'], $refs, true)) {
                        $issues[] = self::issue('term.not_found', $itemPath, "Không tìm thấy {$item['ref']} trong \"{$taxonomy}\" (có thể đã bị xóa) — hãy quét lại cấu trúc và ghép lại chuyên mục.", false);
                    }

                    continue;
                }
                $name = trim((string) ($item['name'] ?? ''));
                $slug = (string) ($item['slug'] ?? '');
                $exists = ($slug !== '' && in_array(mb_strtolower($slug), $slugs, true))
                    || ($name !== '' && in_array(mb_strtolower($name), $names, true));
                if (! $exists && empty($item['create']) && ($name !== '' || $slug !== '')) {
                    $label = $name !== '' ? $name : $slug;
                    $issues[] = self::issue('term.missing', $itemPath, "Chưa có \"{$label}\" trong \"{$taxonomy}\" và không được phép tạo mới — sẽ bỏ qua.", false);
                }
            }
        }
    }

    /** @param  list<array<string, mixed>>  $issues */
    private static function checkRequiredFields(BridgePost $post, ?string $postType, array $schema, array &$issues): void
    {
        $values = $post->fields();
        foreach ((array) ($schema['fields'] ?? []) as $field) {
            if (! is_array($field) || ! ($field['required'] ?? false) || array_key_exists('default', $field)) {
                continue;
            }
            $types = (array) ($field['post_types'] ?? []);
            if ($types !== [] && $postType !== null && ! in_array($postType, $types, true)) {
                continue;
            }
            $ref = (string) ($field['ref'] ?? '');
            if ($ref === '' || ! self::blank($values[$ref] ?? null)) {
                continue;
            }
            $label = (string) ($field['label'] ?? $ref);
            $issues[] = self::issue('field.required', 'target.fields.'.$ref, "Website yêu cầu trường \"{$label}\" — hãy đặt giá trị trong phần Trường tùy chỉnh.");
        }
    }

    /** @param  list<array<string, mixed>>  $issues */
    private static function checkRequiredParams(BridgePost $post, array $schema, array &$issues): void
    {
        $params = $post->params();
        foreach ((array) ($schema['params_spec'] ?? []) as $spec) {
            if (! is_array($spec) || ! ($spec['required'] ?? false)) {
                continue;
            }
            $key = (string) ($spec['key'] ?? '');
            if ($key === '' || ! self::blank($params[$key] ?? null)) {
                continue;
            }
            $label = (string) ($spec['label'] ?? $key);
            $issues[] = self::issue('param.required', 'params.'.$key, "Website yêu cầu tham số \"{$label}\" ({$key}).");
        }
    }

    /** @param  list<array<string, mixed>>  $issues */
    private static function checkLocale(BridgePost $post, array $schema, array &$issues): void
    {
        $locale = $post->locale();
        $known = array_values(array_filter(array_map('strval', (array) ($schema['locales'] ?? []))));
        if ($locale === null || $locale === '' || $known === []) {
            return;
        }
        $norm = fn (string $l) => strtolower(str_replace('_', '-', $l));
        $want = $norm($locale);
        $primary = explode('-', $want)[0];
        foreach ($known as $code) {
            $c = $norm($code);
            if ($c === $want || explode('-', $c)[0] === $primary) {
                return;
            }
        }
        $issues[] = self::issue('locale.unknown', 'target.locale', "Website chưa khai ngôn ngữ \"{$locale}\" (".implode(', ', $known).').', false);
    }

    /** @return list<string> */
    private static function refs(mixed $rows): array
    {
        $out = [];
        foreach ((array) $rows as $row) {
            if (is_array($row) && isset($row['ref']) && $row['ref'] !== '') {
                $out[] = (string) $row['ref'];
            }
        }

        return $out;
    }

    private static function blank(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [] || (is_string($v) && trim($v) === '');
    }

    private static function str(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
