<?php

namespace BaseOS\Connect\Schema;

/**
 * Mirrors the limits/normalization of Base OS `SiteSchemaService` so problems show up in
 * `baseos:doctor` instead of being silently dropped server-side.
 */
final class SchemaValidator
{
    public const FIELD_TYPES = ['string', 'text', 'number', 'bool', 'date', 'ref', 'json', 'select'];

    public const PARAM_TYPES = ['string', 'number', 'bool', 'json'];

    public const BODY_FORMATS = ['html', 'blocks'];

    public const RESERVED_PARAMS = [
        'content', 'target', 'params', 'state', 'remote_id', 'fields_mask', 'options',
        'mapping_version', 'idempotency_key', 'type', 'id',
    ];

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string> problems (empty = valid)
     */
    public static function validate(array $schema): array
    {
        $p = [];

        foreach ((array) ($schema['content_formats'] ?? ['html']) as $f) {
            if (! in_array($f, self::BODY_FORMATS, true)) {
                $p[] = "content_formats: \"{$f}\" is not html|blocks (dropped by Base OS).";
            }
        }

        foreach (['post_types', 'taxonomies', 'fields', 'authors'] as $key) {
            $rows = $schema[$key] ?? [];
            if (! is_array($rows)) {
                $p[] = "{$key}: must be a list.";

                continue;
            }
            if (count($rows) > ($key === 'taxonomies' ? 30 : 200)) {
                $p[] = "{$key}: too many rows (limit ".($key === 'taxonomies' ? 30 : 200).').';
            }
            foreach ($rows as $i => $row) {
                if (! is_array($row) || ! isset($row['ref']) || $row['ref'] === '') {
                    $p[] = "{$key}[{$i}]: missing ref (row ignored by Base OS).";
                }
            }
        }

        foreach ((array) ($schema['taxonomies'] ?? []) as $i => $tax) {
            $terms = is_array($tax) ? (array) ($tax['terms'] ?? []) : [];
            if (count($terms) > 1000) {
                $p[] = "taxonomies[{$i}].terms: more than 1000 terms (truncated).";
            }
            foreach ($terms as $j => $t) {
                if (! is_array($t) || empty($t['ref']) || empty($t['name'])) {
                    $p[] = "taxonomies[{$i}].terms[{$j}]: needs ref + name.";
                }
            }
            if (is_array($tax) && isset($tax['role']) && ! in_array($tax['role'], ['category', 'tag'], true)) {
                $p[] = "taxonomies[{$i}].role: must be category|tag|null.";
            }
        }

        foreach ((array) ($schema['fields'] ?? []) as $i => $f) {
            if (is_array($f) && isset($f['type']) && ! in_array($f['type'], self::FIELD_TYPES, true)) {
                $p[] = "fields[{$i}].type: \"{$f['type']}\" unknown (becomes string).";
            }
            if (is_array($f) && isset($f['ref']) && ! preg_match('/^[A-Za-z0-9_:.\-]{1,120}$/', (string) $f['ref'])) {
                $p[] = "fields[{$i}].ref: must match [A-Za-z0-9_:.-]{1,120}.";
            }
        }

        $specs = $schema['params_spec'] ?? [];
        if (is_array($specs)) {
            if (count($specs) > 50) {
                $p[] = 'params_spec: more than 50 entries.';
            }
            foreach ($specs as $i => $s) {
                $key = is_array($s) ? (string) ($s['key'] ?? '') : '';
                if (! preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $key)) {
                    $p[] = "params_spec[{$i}].key: must match ^[a-z][a-z0-9_.-]{0,63}$.";
                } elseif (in_array($key, self::RESERVED_PARAMS, true) || str_starts_with($key, 'baseos')) {
                    $p[] = "params_spec[{$i}].key: \"{$key}\" is reserved.";
                }
                if (is_array($s) && isset($s['type']) && ! in_array($s['type'], self::PARAM_TYPES, true)) {
                    $p[] = "params_spec[{$i}].type: must be string|number|bool|json.";
                }
            }
        }

        $tenant = $schema['tenant'] ?? null;
        if ($tenant !== null && (! is_array($tenant) || empty($tenant['id']))) {
            $p[] = 'tenant: must be null or {id, label}.';
        }

        if (strlen((string) json_encode($schema)) > 2_000_000) {
            $p[] = 'schema: payload larger than 2 MB.';
        }

        return $p;
    }
}
