<?php

declare(strict_types=1);

namespace Atoll\Cache;

use Atoll\Hooks\HookManager;

final class CacheManager
{
    private string $depIndexPath;

    public function __construct(
        private readonly string $cacheDir,
        private readonly bool $enabled,
        private readonly HookManager $hooks,
        private readonly int $ttl = 3600
    ) {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }

        $this->depIndexPath = rtrim($this->cacheDir, '/') . '/dependency-map.json';
    }

    public function key(string $path): string
    {
        return sha1($path);
    }

    public function get(string $path): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $key = $this->key($path);
        $htmlFile = $this->cacheDir . '/' . $key . '.html';
        $metaFile = $this->cacheDir . '/' . $key . '.json';

        if (!is_file($htmlFile) || !is_file($metaFile)) {
            return null;
        }

        $meta = json_decode((string) file_get_contents($metaFile), true);
        if (!is_array($meta)) {
            return null;
        }

        $expiresAt = (int) ($meta['expires_at'] ?? 0);
        if ($expiresAt > 0 && time() > $expiresAt) {
            @unlink($htmlFile);
            @unlink($metaFile);
            return null;
        }

        return (string) file_get_contents($htmlFile);
    }

    /** @param array<int, string> $dependencies */
    public function put(string $path, string $html, array $dependencies = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $key = $this->key($path);
        file_put_contents($this->cacheDir . '/' . $key . '.html', $html);
        file_put_contents($this->cacheDir . '/' . $key . '.json', json_encode([
            'path' => $path,
            'created_at' => time(),
            'expires_at' => time() + $this->ttl,
            'dependencies' => array_values(array_unique($dependencies)),
        ], JSON_UNESCAPED_SLASHES));

        $depMap = $this->loadDepMap();
        foreach (array_unique($dependencies) as $dep) {
            $depMap[$dep] ??= [];
            if (!in_array($key, $depMap[$dep], true)) {
                $depMap[$dep][] = $key;
            }
        }
        file_put_contents($this->depIndexPath, json_encode($depMap, JSON_UNESCAPED_SLASHES));
    }

    public function clear(): void
    {
        foreach (glob($this->cacheDir . '/*.html') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->cacheDir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
        $this->hooks->run('cache:clear');
    }

    /** @param array<int, string> $dependencies */
    public function invalidateByDependencies(array $dependencies): void
    {
        $depMap = $this->loadDepMap();
        $keys = [];

        foreach ($dependencies as $dep) {
            foreach (($depMap[$dep] ?? []) as $key) {
                $keys[$key] = true;
            }
        }

        foreach (array_keys($keys) as $key) {
            @unlink($this->cacheDir . '/' . $key . '.html');
            @unlink($this->cacheDir . '/' . $key . '.json');
        }
    }

    /** @return array<string, array<int, string>> */
    private function loadDepMap(): array
    {
        if (!is_file($this->depIndexPath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->depIndexPath), true);
        return is_array($decoded) ? $decoded : [];
    }
}
