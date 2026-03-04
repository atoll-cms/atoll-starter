<?php

declare(strict_types=1);

namespace Atoll\Content;

use Atoll\Hooks\HookManager;
use Atoll\Support\Markdown;
use Atoll\Support\Yaml;

final class ContentRepository
{
    public function __construct(
        private readonly string $contentRoot,
        private readonly HookManager $hooks
    ) {
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
        foreach ($this->listCollection($collection, true) as $entry) {
            if ($entry->slug === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /** @return array<int, Page> */
    public function listCollection(string $collection, bool $includeDraft = false): array
    {
        $dir = $this->contentRoot . '/' . trim($collection, '/');
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.md') ?: [];
        $items = [];

        foreach ($files as $file) {
            if (basename($file) === '_collection.md') {
                continue;
            }
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

        return $items;
    }

    /** @return array<string, mixed> */
    public function collectionMeta(string $collection): array
    {
        $path = $this->contentRoot . '/' . trim($collection, '/') . '/_collection.yaml';
        if (!is_file($path)) {
            return [];
        }

        return Yaml::parse((string) file_get_contents($path));
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
}
