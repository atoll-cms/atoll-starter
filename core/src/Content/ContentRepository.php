<?php

declare(strict_types=1);

namespace Atoll\Content;

use Atoll\Hooks\HookManager;
use Atoll\Support\Config;
use Atoll\Support\Markdown;
use Atoll\Support\Yaml;

final class ContentRepository
{
    private ?SqliteContentIndex $index = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly string $contentRoot,
        private readonly HookManager $hooks,
        array $config = [],
        ?string $siteRoot = null
    ) {
        $indexConfig = Config::get($config, 'content.index', []);
        if (!is_array($indexConfig) || !(bool) ($indexConfig['enabled'] ?? false)) {
            return;
        }

        $driver = strtolower(trim((string) ($indexConfig['driver'] ?? 'sqlite')));
        if ($driver !== 'sqlite') {
            return;
        }

        $path = trim((string) ($indexConfig['path'] ?? 'cache/content-index.sqlite'));
        if ($path === '') {
            $path = 'cache/content-index.sqlite';
        }

        if (!$this->isAbsolutePath($path)) {
            $base = $siteRoot ?? dirname($this->contentRoot);
            $path = rtrim($base, '/') . '/' . ltrim($path, '/');
        }

        $this->index = new SqliteContentIndex($this->contentRoot, $path);
    }

    /** @return array<int, string> */
    public function collections(): array
    {
        $dirs = glob($this->contentRoot . '/*', GLOB_ONLYDIR) ?: [];
        $collections = [];
        foreach ($dirs as $dir) {
            $name = basename($dir);
            if ($name === 'data' || $name === 'forms' || $name === 'forms-submissions') {
                continue;
            }
            $collections[] = $name;
        }
        sort($collections);

        return $collections;
    }

    /**
     * @return array{enabled:bool,available?:bool,path?:string,entries?:int,last_rebuild_at?:string,last_update_at?:string,reason:string}
     */
    public function indexStatus(): array
    {
        if ($this->index === null) {
            return [
                'enabled' => false,
                'reason' => 'content.index.enabled is false',
            ];
        }

        return $this->index->status();
    }

    /**
     * @return array{enabled:bool,indexed:int,updated:int,removed:int,path:string}
     */
    public function rebuildIndex(): array
    {
        if ($this->index === null) {
            return [
                'enabled' => false,
                'indexed' => 0,
                'updated' => 0,
                'removed' => 0,
                'path' => '',
            ];
        }

        return $this->index->rebuild();
    }

    public function getPage(string $slug): ?Page
    {
        $slug = $slug === '' ? 'index' : trim($slug, '/');
        $path = $this->contentRoot . '/pages/' . $slug . '.md';
        if (!is_file($path)) {
            return null;
        }

        return $this->fromFile('pages', $path, '/' . ($slug === 'index' ? '' : $slug));
    }

    public function getCollectionEntryBySlug(string $collection, string $slug): ?Page
    {
        if ($this->index !== null) {
            $meta = $this->index->findBySlug($collection, $slug, true);
            if ($meta !== null) {
                $entry = $this->fromFile($collection, (string) $meta['source_path'], null);
                if ($entry !== null) {
                    if ($entry->slug !== $slug) {
                        $this->index->upsertFile($collection, $entry->sourcePath);
                    } else {
                        return $entry;
                    }
                }
            }
        }

        foreach ($this->listCollectionFromFiles($collection, true) as $entry) {
            if ($entry->slug === $slug) {
                if ($this->index !== null) {
                    $this->index->upsertFile($collection, $entry->sourcePath);
                }
                return $entry;
            }
        }

        return null;
    }

    /** @return array<int, Page> */
    public function listCollection(string $collection, bool $includeDraft = false): array
    {
        $collection = trim($collection, '/');
        $dir = $this->contentRoot . '/' . $collection;
        if (!is_dir($dir)) {
            return [];
        }

        if ($this->index !== null) {
            $metaRows = $this->index->listCollection($collection, $includeDraft, $this->collectionMeta($collection));
            if ($metaRows !== [] || !$this->collectionHasMarkdownFiles($collection)) {
                return $this->listCollectionFromIndexedMeta($collection, $metaRows, $includeDraft);
            }
        }

        return $this->listCollectionFromFiles($collection, $includeDraft);
    }

    /** @return array<string, mixed> */
    public function collectionMeta(string $collection): array
    {
        $path = $this->contentRoot . '/' . trim($collection, '/') . '/_collection.yaml';
        if (!is_file($path)) {
            return [];
        }

        $parsed = Yaml::parse((string) file_get_contents($path));
        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function saveCollectionMeta(string $collection, array $meta): string
    {
        $collection = trim($collection, '/');
        if ($collection === '') {
            throw new ValidationException(['collection' => 'required'], 'Collection is required');
        }

        $dir = $this->contentRoot . '/' . $collection;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $schema = $meta['schema'] ?? [];
        if (!is_array($schema)) {
            throw new ValidationException(['schema' => 'schema_must_be_object'], 'Schema must be an object');
        }

        $normalizedSchema = [];
        foreach ($schema as $field => $rules) {
            if (!is_string($field) || trim($field) === '') {
                continue;
            }
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
                throw new ValidationException(['schema.' . $field => 'invalid_field_name'], 'Invalid schema field name');
            }
            if (!is_array($rules)) {
                throw new ValidationException(['schema.' . $field => 'rules_must_be_object'], 'Schema rules must be objects');
            }
            $normalizedSchema[$field] = $rules;
        }

        $meta['schema'] = $normalizedSchema;

        $file = $dir . '/_collection.yaml';
        file_put_contents($file, Yaml::dump($meta));

        return $file;
    }

    /** @return array<string, mixed> */
    public function readDataFile(string $name): array
    {
        $path = $this->contentRoot . '/data/' . ltrim($name, '/');
        if (!is_file($path)) {
            return [];
        }

        if (str_ends_with($path, '.yaml') || str_ends_with($path, '.yml')) {
            $parsed = Yaml::parse((string) file_get_contents($path));
            return is_array($parsed) ? $parsed : [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getById(string $collection, string $id): ?Page
    {
        $path = $this->contentRoot . '/' . trim($collection, '/') . '/' . $id . '.md';
        if (!is_file($path)) {
            return null;
        }

        return $this->fromFile($collection, $path, null);
    }

    /**
     * @return array<int, array{url:string,source_path:string}>
     */
    public function sitemapEntries(): array
    {
        if ($this->index !== null) {
            $rows = $this->index->sitemapEntries();
            if ($rows !== [] || !$this->hasPublicContentFiles()) {
                return $rows;
            }
        }

        $rows = [];
        foreach ($this->allPublicPages() as $page) {
            $rows[] = [
                'url' => $page->url,
                'source_path' => $page->sourcePath,
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $frontmatter */
    public function save(string $collection, string $id, array $frontmatter, string $markdown): string
    {
        $collection = trim($collection, '/');
        $id = trim($id, '/');
        $frontmatter = $this->applySchemaDefaults($collection, $frontmatter);
        $this->validateSchema($collection, $frontmatter);

        $dir = $this->contentRoot . '/' . $collection;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = $dir . '/' . $id . '.md';
        $payload = "---\n" . Yaml::dump($frontmatter) . "---\n\n" . trim($markdown) . "\n";
        file_put_contents($file, $payload);

        if ($this->index !== null) {
            $this->index->upsertFile($collection, $file);
        }

        $this->hooks->run('content:save', [
            'collection' => $collection,
            'id' => $id,
            'file' => $file,
            'frontmatter' => $frontmatter,
        ]);

        return $file;
    }

    public function delete(string $collection, string $id): bool
    {
        $file = $this->contentRoot . '/' . trim($collection, '/') . '/' . trim($id, '/') . '.md';
        if (!is_file($file)) {
            return false;
        }

        $deleted = unlink($file);
        if ($deleted) {
            if ($this->index !== null) {
                $this->index->removeFile($file);
            }
            $this->hooks->run('content:delete', ['collection' => $collection, 'id' => $id, 'file' => $file]);
        }

        return $deleted;
    }

    /** @return array<int, Page> */
    public function allPublicPages(): array
    {
        $pages = [];

        foreach ($this->listCollection('pages') as $page) {
            $pages[] = $page;
        }

        foreach ($this->collections() as $collection) {
            if ($collection === 'pages') {
                continue;
            }
            foreach ($this->listCollection($collection) as $entry) {
                $pages[] = $entry;
            }
        }

        return $pages;
    }

    private function fromFile(string $collection, string $path, ?string $forcedUrl): ?Page
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = (string) file_get_contents($path);
        [$frontmatter, $markdown] = $this->splitFrontmatter($raw);

        foreach ($this->hooks->run('content:before_parse', $markdown, $path, $frontmatter) as $result) {
            if (is_string($result)) {
                $markdown = $result;
            }
        }

        $html = Markdown::toHtml($markdown);
        foreach ($this->hooks->run('content:after_parse', $html, $path, $frontmatter) as $result) {
            if (is_string($result)) {
                $html = $result;
            }
        }

        $filename = pathinfo($path, PATHINFO_FILENAME);
        $slug = $this->slugFromFilename($collection, $filename);

        $url = $forcedUrl;
        if ($url === null) {
            $url = $collection === 'pages' ? '/' . ($slug === 'index' ? '' : $slug) : '/' . $collection . '/' . $slug;
            if ($url !== '/' && str_ends_with($url, '/')) {
                $url = rtrim($url, '/');
            }
        }

        return new Page(
            id: $filename,
            collection: $collection,
            slug: $slug,
            sourcePath: $path,
            url: $url,
            data: $frontmatter,
            markdown: $markdown,
            content: $html
        );
    }

    /**
     * @return array<int, Page>
     */
    private function listCollectionFromIndexedMeta(string $collection, array $metaRows, bool $includeDraft): array
    {
        $items = [];

        foreach ($metaRows as $meta) {
            if (!is_array($meta)) {
                continue;
            }

            $sourcePath = (string) ($meta['source_path'] ?? '');
            if ($sourcePath === '') {
                continue;
            }

            $entry = $this->fromFile($collection, $sourcePath, null);
            if ($entry === null) {
                if ($this->index !== null) {
                    $this->index->removeFile($sourcePath);
                }
                continue;
            }
            if (!$includeDraft && (bool) ($entry->data['draft'] ?? false)) {
                continue;
            }
            if ($this->index !== null && (string) ($meta['slug'] ?? '') !== $entry->slug) {
                $this->index->upsertFile($collection, $entry->sourcePath);
            }
            $items[] = $entry;
        }

        return $items;
    }

    /**
     * @return array<int, Page>
     */
    private function listCollectionFromFiles(string $collection, bool $includeDraft): array
    {
        $dir = $this->contentRoot . '/' . trim($collection, '/');
        if (!is_dir($dir)) {
            return [];
        }

        $files = $this->collectionMarkdownFiles($collection);
        $items = [];

        foreach ($files as $file) {
            $entry = $this->fromFile($collection, $file, null);
            if ($entry === null) {
                continue;
            }
            if (!$includeDraft && (bool) ($entry->data['draft'] ?? false)) {
                continue;
            }
            $items[] = $entry;
        }

        $meta = $this->collectionMeta($collection);
        $sort = (string) ($meta['sort'] ?? 'date desc');
        [$field, $direction] = array_pad(explode(' ', $sort, 2), 2, 'desc');
        usort($items, static function (Page $a, Page $b) use ($field, $direction): int {
            $av = $a->data[$field] ?? '';
            $bv = $b->data[$field] ?? '';
            $cmp = $av <=> $bv;
            return strtolower($direction) === 'asc' ? $cmp : -$cmp;
        });

        if ($this->index !== null) {
            foreach ($items as $entry) {
                $this->index->upsertFile($collection, $entry->sourcePath);
            }
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    private function collectionMarkdownFiles(string $collection): array
    {
        $dir = $this->contentRoot . '/' . trim($collection, '/');
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.md') ?: [];
        $rows = [];
        foreach ($files as $file) {
            if (basename($file) === '_collection.md') {
                continue;
            }
            $rows[] = $file;
        }
        sort($rows);
        return $rows;
    }

    private function collectionHasMarkdownFiles(string $collection): bool
    {
        return $this->collectionMarkdownFiles($collection) !== [];
    }

    private function hasPublicContentFiles(): bool
    {
        foreach ($this->collections() as $collection) {
            if ($this->collectionHasMarkdownFiles($collection)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0:array<string,mixed>,1:string} */
    private function splitFrontmatter(string $raw): array
    {
        if (preg_match('/^---\s*\R(.*?)\R---\s*\R?(.*)$/s', $raw, $matches) !== 1) {
            return [[], $raw];
        }

        $frontmatter = Yaml::parse($matches[1]);
        return [is_array($frontmatter) ? $frontmatter : [], $matches[2]];
    }

    private function slugFromFilename(string $collection, string $filename): string
    {
        $meta = $this->collectionMeta($collection);
        $slugSource = (string) ($meta['slug_from'] ?? 'filename');
        if ($slugSource !== 'filename') {
            return $filename;
        }

        // 2025-01-hello-world -> hello-world
        return (string) preg_replace('/^\d{4}-\d{2}-/', '', $filename);
    }

    /**
     * @param array<string, mixed> $frontmatter
     * @return array<string, mixed>
     */
    private function applySchemaDefaults(string $collection, array $frontmatter): array
    {
        $schema = $this->collectionMeta($collection)['schema'] ?? null;
        if (!is_array($schema)) {
            return $frontmatter;
        }

        foreach ($schema as $field => $rules) {
            if (!is_string($field) || !is_array($rules)) {
                continue;
            }
            if (!array_key_exists($field, $frontmatter) && array_key_exists('default', $rules)) {
                $frontmatter[$field] = $rules['default'];
            }
        }

        return $frontmatter;
    }

    /**
     * @param array<string, mixed> $frontmatter
     */
    private function validateSchema(string $collection, array $frontmatter): void
    {
        $schema = $this->collectionMeta($collection)['schema'] ?? null;
        if (!is_array($schema) || $schema === []) {
            return;
        }

        $errors = [];

        foreach ($schema as $field => $rules) {
            if (!is_string($field) || !is_array($rules)) {
                continue;
            }

            $required = (bool) ($rules['required'] ?? false);
            $exists = array_key_exists($field, $frontmatter);
            $value = $frontmatter[$field] ?? null;

            if ($required && (!$exists || $value === null || $value === '')) {
                $errors[$field] = 'required';
                continue;
            }

            if (!$exists || $value === null || $value === '') {
                continue;
            }

            $type = (string) ($rules['type'] ?? 'string');
            if (!$this->valueMatchesType($type, $value, $rules)) {
                $errors[$field] = 'invalid_type:' . $type;
                continue;
            }

            $maxLength = isset($rules['max_length']) ? (int) $rules['max_length'] : 0;
            if ($maxLength > 0 && is_string($value) && mb_strlen($value) > $maxLength) {
                $errors[$field] = 'max_length_exceeded:' . $maxLength;
                continue;
            }

            if ($type === 'number' || $type === 'integer') {
                $number = is_int($value) || is_float($value) ? (float) $value : (is_string($value) && is_numeric($value) ? (float) $value : null);
                if ($number !== null) {
                    if (array_key_exists('min', $rules) && $number < (float) $rules['min']) {
                        $errors[$field] = 'min_value_not_met:' . (string) $rules['min'];
                        continue;
                    }
                    if (array_key_exists('max', $rules) && $number > (float) $rules['max']) {
                        $errors[$field] = 'max_value_exceeded:' . (string) $rules['max'];
                        continue;
                    }
                }
            }

            if (is_array($value)) {
                $minItems = isset($rules['min_items']) ? (int) $rules['min_items'] : 0;
                $maxItems = isset($rules['max_items']) ? (int) $rules['max_items'] : 0;
                $count = count($value);

                if ($minItems > 0 && $count < $minItems) {
                    $errors[$field] = 'min_items_not_met:' . $minItems;
                    continue;
                }
                if ($maxItems > 0 && $count > $maxItems) {
                    $errors[$field] = 'max_items_exceeded:' . $maxItems;
                    continue;
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function valueMatchesType(string $type, mixed $value, array $rules): bool
    {
        return match ($type) {
            'string', 'text' => is_string($value),
            'date' => is_string($value) && strtotime($value) !== false,
            'boolean' => is_bool($value),
            'image' => is_string($value) && str_starts_with($value, '/'),
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'json' => is_array($value),
            'repeater' => $this->matchesRepeaterType($value),
            'flexible' => $this->matchesFlexibleType($value),
            'relation' => $this->matchesListType($value, 'string'),
            'list' => $this->matchesListType($value, (string) ($rules['of'] ?? 'string')),
            default => true,
        };
    }

    private function matchesListType(mixed $value, string $of): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if ($of === 'string' && !is_string($item)) {
                return false;
            }
        }

        return true;
    }

    private function matchesRepeaterType(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_array($item)) {
                return false;
            }
        }

        return true;
    }

    private function matchesFlexibleType(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_array($item)) {
                return false;
            }
            $type = $item['type'] ?? null;
            if (!is_string($type) || trim($type) === '') {
                return false;
            }
        }

        return true;
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, '/')) {
            return true;
        }

        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
