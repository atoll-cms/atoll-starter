<?php

declare(strict_types=1);

use Atoll\Support\Yaml;

$root = dirname(__DIR__, 2);

$loadSchema = static function (string $collection) use ($root): array {
    $collection = trim($collection, '/');
    if ($collection === '') {
        return [];
    }

    $path = $root . '/content/' . $collection . '/_collection.yaml';
    if (!is_file($path)) {
        return [];
    }

    $meta = Yaml::parse((string) file_get_contents($path));
    $schema = $meta['schema'] ?? [];
    return is_array($schema) ? $schema : [];
};

$normalizeByType = static function (string $type, mixed $value): array {
    $type = strtolower(trim($type));

    if ($value === null || $value === '') {
        return ['ok' => true, 'value' => null];
    }

    switch ($type) {
        case 'string':
        case 'text':
        case 'date':
        case 'image':
            return ['ok' => true, 'value' => is_string($value) ? trim($value) : (string) $value];

        case 'boolean':
            if (is_bool($value)) {
                return ['ok' => true, 'value' => $value];
            }
            if (is_numeric($value)) {
                return ['ok' => true, 'value' => ((int) $value) !== 0];
            }
            if (is_string($value)) {
                $normalized = strtolower(trim($value));
                if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                    return ['ok' => true, 'value' => true];
                }
                if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                    return ['ok' => true, 'value' => false];
                }
            }
            return ['ok' => false, 'error' => 'invalid_boolean'];

        case 'number':
            if (is_int($value) || is_float($value)) {
                return ['ok' => true, 'value' => $value + 0];
            }
            if (is_string($value) && is_numeric(trim($value))) {
                return ['ok' => true, 'value' => (float) trim($value)];
            }
            return ['ok' => false, 'error' => 'invalid_number'];

        case 'integer':
            if (is_int($value)) {
                return ['ok' => true, 'value' => $value];
            }
            if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
                return ['ok' => true, 'value' => (int) trim($value)];
            }
            return ['ok' => false, 'error' => 'invalid_integer'];

        case 'list':
        case 'relation':
            if (is_array($value)) {
                $list = array_values(array_filter(array_map(static fn (mixed $v): string => trim((string) $v), $value), static fn (string $v): bool => $v !== ''));
                return ['ok' => true, 'value' => $list];
            }
            if (is_string($value)) {
                $parts = array_map('trim', explode(',', $value));
                $list = array_values(array_filter($parts, static fn (string $v): bool => $v !== ''));
                return ['ok' => true, 'value' => $list];
            }
            return ['ok' => false, 'error' => 'invalid_list'];

        case 'json':
            if (is_array($value)) {
                return ['ok' => true, 'value' => $value];
            }
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return ['ok' => true, 'value' => $decoded];
                }
            }
            return ['ok' => false, 'error' => 'invalid_json'];

        case 'repeater':
        case 'flexible':
            if (is_array($value)) {
                return ['ok' => true, 'value' => array_values($value)];
            }
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    return ['ok' => true, 'value' => array_values($decoded)];
                }
            }
            return ['ok' => false, 'error' => 'invalid_array_json'];

        default:
            return ['ok' => true, 'value' => $value];
    }
};

return [
    'name' => 'custom-fields',
    'description' => 'Collection schema builder with advanced field types',
    'version' => '0.1.0',
    'hooks' => [
        'admin:menu' => static fn (): array => [
            'id' => 'custom-fields',
            'label' => 'Custom Fields',
            'icon' => 'M4 7h16M4 12h16M4 17h10m7-7v7m0 0l-3-3m3 3l3-3',
            'route' => '/admin#custom-fields',
        ],
        'admin:dashboard' => static fn (): array => [
            'id' => 'custom-fields',
            'title' => 'Custom Fields',
            'value' => 'active',
            'text' => 'Schema-Builder fuer Repeater, Flexible-Blocks und Beziehungen.',
        ],
        'admin:entry:before_save' => static function (array $payload) use ($loadSchema, $normalizeByType): array {
            $collection = (string) ($payload['collection'] ?? '');
            $frontmatter = $payload['frontmatter'] ?? null;
            if (!is_array($frontmatter) || $collection === '') {
                return [];
            }

            $schema = $loadSchema($collection);
            if ($schema === []) {
                return [];
            }

            $errors = [];
            foreach ($schema as $field => $rules) {
                if (!is_string($field) || !is_array($rules)) {
                    continue;
                }
                if (!array_key_exists($field, $frontmatter)) {
                    continue;
                }

                $value = $frontmatter[$field];
                $type = (string) ($rules['type'] ?? 'string');
                $normalized = $normalizeByType($type, $value);

                if (($normalized['ok'] ?? false) !== true) {
                    $errors[$field] = (string) ($normalized['error'] ?? 'invalid');
                    continue;
                }

                $frontmatter[$field] = $normalized['value'] ?? null;
            }

            return [
                'frontmatter' => $frontmatter,
                'errors' => $errors,
            ];
        },
    ],
    'routes' => [],
    'islands' => [],
    'admin_pages' => [
        'custom-fields' => 'admin/custom-fields.html',
    ],
];
