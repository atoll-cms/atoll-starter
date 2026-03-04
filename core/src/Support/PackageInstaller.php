<?php

declare(strict_types=1);

namespace Atoll\Support;

use RuntimeException;
use ZipArchive;

final class PackageInstaller
{
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function installPlugin(
        string $root,
        string $source,
        bool $force = false,
        bool $enable = false,
        array $config = []
    ): array {
        $sourceDir = self::resolveInstallSource($root, $source, 'plugin', $config);
        if (!is_file($sourceDir . '/plugin.php')) {
            throw new RuntimeException('Invalid plugin package: plugin.php missing at root.');
        }

        $id = self::normalizePackageId(basename($sourceDir));
        $dest = rtrim($root, '/') . '/plugins/' . $id;
        $sourceReal = realpath($sourceDir);
        $destReal = realpath($dest);

        if (!($sourceReal !== false && $destReal !== false && $sourceReal === $destReal)) {
            self::prepareDestination($dest, $force, "Plugin already exists: {$id} (use --force to overwrite)");
            self::copyDirectory($sourceDir, $dest);
        }

        if ($enable) {
            $stateFile = rtrim($root, '/') . '/content/data/plugins.yaml';
            $state = is_file($stateFile) ? Yaml::parse((string) file_get_contents($stateFile)) : [];
            $state = is_array($state) ? $state : [];
            $state[$id] = true;

            if (!is_dir(dirname($stateFile))) {
                mkdir(dirname($stateFile), 0775, true);
            }
            file_put_contents($stateFile, Yaml::dump($state));
        }

        return [
            'ok' => true,
            'id' => $id,
            'path' => $dest,
            'enabled' => $enable,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function installTheme(
        string $root,
        string $source,
        bool $force = false,
        array $config = [],
        ?string $targetId = null
    ): array {
        $sourceDir = self::resolveInstallSource($root, $source, 'theme', $config);
        if (!is_dir($sourceDir . '/templates')) {
            throw new RuntimeException('Invalid theme package: templates/ missing at root.');
        }

        $id = self::normalizePackageId($targetId ?? basename($sourceDir));
        $dest = rtrim($root, '/') . '/themes/' . $id;
        $sourceReal = realpath($sourceDir);
        $destReal = realpath($dest);
        if (!($sourceReal !== false && $destReal !== false && $sourceReal === $destReal)) {
            self::prepareDestination($dest, $force, "Theme already exists: {$id} (use --force to overwrite)");
            self::copyDirectory($sourceDir, $dest);
        }

        return [
            'ok' => true,
            'id' => $id,
            'path' => $dest,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function installPluginFromRegistry(
        string $root,
        string $id,
        bool $force = false,
        bool $enable = true,
        array $config = []
    ): array {
        $entry = self::findRegistryEntry(rtrim($root, '/') . '/content/data/plugin-registry.json', $id);
        $source = (string) ($entry['source'] ?? '');
        if ($source === '') {
            throw new RuntimeException("Registry entry '{$id}' has no source.");
        }

        return self::installPlugin($root, $source, $force, $enable, $config);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function installThemeFromRegistry(
        string $root,
        string $id,
        bool $force = false,
        array $config = []
    ): array {
        $entry = self::findRegistryEntry(rtrim($root, '/') . '/content/data/theme-registry.json', $id);
        $source = (string) ($entry['source'] ?? '');
        if ($source === '') {
            throw new RuntimeException("Registry entry '{$id}' has no source.");
        }

        return self::installTheme($root, $source, $force, $config, $id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function loadRegistry(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $rows[] = $entry;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function resolveInstallSource(string $root, string $source, string $kind, array $config): string
    {
        $resolvedInput = trim($source);
        if ($resolvedInput === '') {
            throw new RuntimeException("{$kind} source cannot be empty.");
        }

        $cacheRoot = rtrim($root, '/') . '/cache';
        if (self::isRemoteSource($resolvedInput)) {
            $downloads = $cacheRoot . '/downloads';
            if (!is_dir($downloads)) {
                mkdir($downloads, 0775, true);
            }
            $resolvedInput = self::downloadSource($resolvedInput, $downloads, $config);
        } elseif (!str_starts_with($resolvedInput, '/')) {
            $resolvedInput = rtrim($root, '/') . '/' . ltrim($resolvedInput, '/');
        }

        $resolved = realpath($resolvedInput);
        if ($resolved === false) {
            throw new RuntimeException("{$kind} source not found: {$source}");
        }

        if (is_dir($resolved)) {
            return rtrim($resolved, '/');
        }

        if (!str_ends_with(strtolower($resolved), '.zip')) {
            throw new RuntimeException("{$kind} source must be a directory or .zip archive");
        }

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required for zip installs.');
        }

        $extractRoot = self::createUniqueWorkDir($cacheRoot, '.' . $kind . '-install-');
        $zip = new ZipArchive();
        if ($zip->open($resolved) !== true) {
            throw new RuntimeException("Could not open zip archive: {$resolved}");
        }
        $zip->extractTo($extractRoot);
        $zip->close();

        $dirs = glob($extractRoot . '/*', GLOB_ONLYDIR) ?: [];
        if (count($dirs) === 1) {
            return $dirs[0];
        }

        return $extractRoot;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function downloadSource(string $url, string $downloadDir, array $config): string
    {
        $timeout = (int) Config::get($config, 'updater.timeout_seconds', 20);
        $filename = basename(parse_url($url, PHP_URL_PATH) ?: 'package.zip');
        $filename = $filename !== '' ? $filename : 'package.zip';
        $target = rtrim($downloadDir, '/') . '/' . $filename;

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
        ]);

        $binary = @file_get_contents($url, false, $context);
        if (!is_string($binary) || $binary === '') {
            throw new RuntimeException('Could not download package source: ' . $url);
        }

        file_put_contents($target, $binary);
        return $target;
    }

    private static function isRemoteSource(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }

    /**
     * @return array<string, mixed>
     */
    private static function findRegistryEntry(string $file, string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            throw new RuntimeException('Registry id cannot be empty.');
        }

        $registry = self::loadRegistry($file);
        foreach ($registry as $entry) {
            if (($entry['id'] ?? null) === $id) {
                return $entry;
            }
        }

        throw new RuntimeException("Registry entry not found: {$id}");
    }

    private static function prepareDestination(string $destination, bool $force, string $existsError): void
    {
        if (!is_dir($destination)) {
            return;
        }

        if (!$force) {
            throw new RuntimeException($existsError);
        }

        self::rrmdir($destination);
    }

    private static function normalizePackageId(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9._-]/', '-', $value));
        $slug = trim($slug, '-._');
        return $slug !== '' ? $slug : 'package';
    }

    private static function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new RuntimeException('copyDirectory source missing: ' . $source);
        }

        if (!is_dir($destination)) {
            mkdir($destination, 0775, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $relative = substr($sourcePath, strlen($source) + 1);
            $targetPath = $destination . '/' . $relative;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0775, true);
                }
            } else {
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0775, true);
                }
                copy($sourcePath, $targetPath);
            }
        }
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    private static function createUniqueWorkDir(string $parentDir, string $prefix): string
    {
        if (!is_dir($parentDir)) {
            mkdir($parentDir, 0775, true);
        }

        for ($i = 0; $i < 20; $i++) {
            $suffix = bin2hex(random_bytes(3));
            $candidate = rtrim($parentDir, '/') . '/' . $prefix . date('Ymd-His') . '-' . $suffix;
            if (!file_exists($candidate)) {
                mkdir($candidate, 0775, true);
                return $candidate;
            }
        }

        throw new RuntimeException('Could not allocate temporary directory.');
    }
}
