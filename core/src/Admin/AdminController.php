<?php

declare(strict_types=1);

namespace Atoll\Admin;

use Atoll\Backup\BackupManager;
use Atoll\Cache\CacheManager;
use Atoll\Content\ContentRepository;
use Atoll\Content\ValidationException;
use Atoll\Hooks\HookManager;
use Atoll\Http\Request;
use Atoll\Http\Response;
use Atoll\Media\MediaManager;
use Atoll\Plugins\PluginManager;
use Atoll\Security\SecurityManager;
use Atoll\Support\Config;
use Atoll\Support\PackageInstaller;
use Atoll\Support\Yaml;
use RuntimeException;

final class AdminController
{
    /**
     * @param array<string, mixed> $config
     * @param array<int, string> $adminRoots
     */
    public function __construct(
        private readonly string $root,
        private readonly string $configPath,
        private array $config,
        private readonly array $adminRoots,
        private readonly HookManager $hooks,
        private readonly SecurityManager $security,
        private readonly ContentRepository $content,
        private readonly CacheManager $cache,
        private readonly PluginManager $plugins,
        private readonly BackupManager $backup,
        private readonly MediaManager $media
    ) {
    }

    public function serveSpa(): Response
    {
        $index = $this->resolveAdminFile('index.html');
        if (!is_file($index)) {
            return Response::html('<h1>Admin panel missing</h1>', 500);
        }

        $html = (string) file_get_contents($index);
        $csrf = $this->security->csrfToken();
        $injected = str_replace('__ATOLL_CSRF__', htmlspecialchars($csrf, ENT_QUOTES), $html);

        return Response::html($injected);
    }

    public function serveAsset(string $relativePath): ?Response
    {
        $relativePath = trim($relativePath, '/');
        if ($relativePath === '') {
            return null;
        }

        $file = $this->resolveAdminFile($relativePath);
        if (!is_file($file)) {
            return null;
        }

        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        $contentType = match ($ext) {
            'js' => 'application/javascript; charset=UTF-8',
            'css' => 'text/css; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };

        return Response::text((string) file_get_contents($file))
            ->withHeader('Content-Type', $contentType);
    }

    public function handleApi(Request $request): Response
    {
        $endpoint = '/' . trim(substr($request->path, strlen('/admin/api')), '/');
        if ($endpoint === '/auth/login' && $request->method === 'POST') {
            return $this->login($request);
        }

        if ($endpoint === '/auth/logout' && $request->method === 'POST') {
            $this->security->logout($request);
            return Response::json(['ok' => true]);
        }

        if (!$this->security->isAuthenticated()) {
            return Response::json(['error' => 'Unauthenticated'], 401);
        }

        if ($request->method === 'POST') {
            $token = $request->header('x-csrf-token', $request->input('_csrf', ''));
            if (!$this->security->validateCsrf($token)) {
                return Response::json(['error' => 'Invalid CSRF token'], 419);
            }
        }

        return match (true) {
            $endpoint === '/me' && $request->method === 'GET' => Response::json([
                'ok' => true,
                'user' => $this->security->currentUser(),
                'csrf' => $this->security->csrfToken(),
                'security' => [
                    'twofa_enabled' => $this->isCurrentUserTwoFactorEnabled(),
                ],
            ]),
            $endpoint === '/menu' && $request->method === 'GET' => $this->adminMenu(),
            $endpoint === '/dashboard/widgets' && $request->method === 'GET' => $this->dashboardWidgets(),
            $endpoint === '/collections' && $request->method === 'GET' => Response::json([
                'ok' => true,
                'collections' => $this->content->collections(),
            ]),
            $endpoint === '/entries' && $request->method === 'GET' => $this->entries($request),
            $endpoint === '/entry' && $request->method === 'GET' => $this->entry($request),
            $endpoint === '/entry/save' && $request->method === 'POST' => $this->saveEntry($request),
            $endpoint === '/entry/delete' && $request->method === 'POST' => $this->deleteEntry($request),
            $endpoint === '/forms/submissions' && $request->method === 'GET' => $this->formSubmissions($request),
            $endpoint === '/cache/clear' && $request->method === 'POST' => $this->clearCache(),
            $endpoint === '/backup/create' && $request->method === 'POST' => $this->createBackup(),
            $endpoint === '/plugins' && $request->method === 'GET' => Response::json(['ok' => true, 'plugins' => $this->plugins->list()]),
            $endpoint === '/plugin-registry' && $request->method === 'GET' => $this->pluginRegistry(),
            $endpoint === '/theme-registry' && $request->method === 'GET' => $this->themeRegistry(),
            $endpoint === '/plugins/toggle' && $request->method === 'POST' => $this->togglePlugin($request),
            $endpoint === '/plugins/install' && $request->method === 'POST' => $this->installPlugin($request),
            $endpoint === '/themes' && $request->method === 'GET' => $this->themes(),
            $endpoint === '/themes/install' && $request->method === 'POST' => $this->installTheme($request),
            $endpoint === '/themes/activate' && $request->method === 'POST' => $this->activateTheme($request),
            $endpoint === '/themes/uninstall' && $request->method === 'POST' => $this->uninstallTheme($request),
            $endpoint === '/media/upload' && $request->method === 'POST' => $this->uploadMedia($request),
            $endpoint === '/media/list' && $request->method === 'GET' => $this->listMedia($request),
            $endpoint === '/media/transform' && $request->method === 'POST' => $this->transformMedia($request),
            $endpoint === '/security/audit' && $request->method === 'GET' => $this->securityAudit($request),
            $endpoint === '/security/2fa/setup' && $request->method === 'POST' => $this->setupTwoFactor($request),
            $endpoint === '/security/2fa/disable' && $request->method === 'POST' => $this->disableTwoFactor($request),
            $endpoint === '/settings' && $request->method === 'GET' => $this->settings(),
            $endpoint === '/settings/save' && $request->method === 'POST' => $this->saveSettings($request),
            default => Response::json(['error' => 'Not found', 'endpoint' => $endpoint], 404),
        };
    }

    private function login(Request $request): Response
    {
        if (!$this->security->isAdminIpAllowed($request)) {
            $this->security->recordAudit('auth.login_denied_ip', [
                'ip' => $request->server['REMOTE_ADDR'] ?? 'unknown',
            ]);
            return Response::json(['error' => 'Login from this IP is not allowed'], 403);
        }

        $rateLimit = $this->security->enforceRateLimit($request, 'admin-login', 10, 60);
        if ($rateLimit !== null) {
            return $rateLimit;
        }

        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');
        $otp = trim((string) $request->input('otp', ''));

        $users = Config::get($this->config, 'users', []);
        if (!is_array($users)) {
            return Response::json(['error' => 'Auth config missing'], 500);
        }

        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            if (($user['username'] ?? null) !== $username) {
                continue;
            }

            $hash = (string) ($user['password_hash'] ?? '');
            if ($hash !== '' && password_verify($password, $hash)) {
                $twoFactorSecret = (string) ($user['twofa_secret'] ?? '');
                if ($twoFactorSecret !== '' && !$this->security->verifyTotp($twoFactorSecret, $otp)) {
                    $this->security->recordAudit('auth.login_failed_2fa', [
                        'user' => $username,
                        'ip' => $request->server['REMOTE_ADDR'] ?? 'unknown',
                    ]);
                    return Response::json(['error' => 'Invalid 2FA code'], 401);
                }

                $this->security->login($username, $request);
                $this->hooks->run('auth:login', $username, $request);
                return Response::json([
                    'ok' => true,
                    'user' => $username,
                    'csrf' => $this->security->csrfToken(),
                    'security' => [
                        'twofa_enabled' => $twoFactorSecret !== '',
                    ],
                ]);
            }
        }

        $this->security->recordAudit('auth.login_failed_password', [
            'user' => $username,
            'ip' => $request->server['REMOTE_ADDR'] ?? 'unknown',
        ]);
        return Response::json(['error' => 'Invalid credentials'], 401);
    }

    private function entries(Request $request): Response
    {
        $collection = (string) $request->input('collection', 'pages');
        $items = $this->content->listCollection($collection, true);

        return Response::json([
            'ok' => true,
            'entries' => array_map(static fn ($p) => $p->toArray(), $items),
        ]);
    }

    private function adminMenu(): Response
    {
        $items = [];
        $seen = [];
        foreach ($this->hooks->run('admin:menu', $this->security->currentUser()) as $result) {
            if (is_array($result) && array_is_list($result)) {
                foreach ($result as $entry) {
                    $this->appendAdminMenuItem($items, $seen, $entry);
                }
                continue;
            }

            $this->appendAdminMenuItem($items, $seen, $result);
        }

        return Response::json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    private function dashboardWidgets(): Response
    {
        $widgets = [];
        $counter = 0;
        foreach ($this->hooks->run('admin:dashboard', $this->security->currentUser()) as $result) {
            if (is_array($result) && array_is_list($result)) {
                foreach ($result as $widget) {
                    $this->appendDashboardWidget($widgets, $widget, $counter);
                }
                continue;
            }

            $this->appendDashboardWidget($widgets, $result, $counter);
        }

        return Response::json([
            'ok' => true,
            'widgets' => $widgets,
        ]);
    }

    private function entry(Request $request): Response
    {
        $collection = (string) $request->input('collection', 'pages');
        $id = (string) $request->input('id', 'index');
        $entry = $this->content->getById($collection, $id);
        if ($entry === null) {
            return Response::json(['error' => 'Entry not found'], 404);
        }

        return Response::json(['ok' => true, 'entry' => $entry->toArray()]);
    }

    private function saveEntry(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $collection = (string) ($payload['collection'] ?? 'pages');
        $id = (string) ($payload['id'] ?? 'index');
        $frontmatter = $payload['frontmatter'] ?? [];
        $markdown = (string) ($payload['markdown'] ?? '');

        if (!is_array($frontmatter)) {
            return Response::json(['error' => 'frontmatter must be object'], 422);
        }

        try {
            $file = $this->content->save($collection, $id, $frontmatter, $markdown);
        } catch (ValidationException $e) {
            return Response::json([
                'error' => $e->getMessage(),
                'fields' => $e->errors(),
            ], 422);
        }
        $this->cache->invalidateByDependencies([$file]);

        return Response::json(['ok' => true, 'file' => $file]);
    }

    private function deleteEntry(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $collection = (string) ($payload['collection'] ?? 'pages');
        $id = (string) ($payload['id'] ?? '');

        if ($id === '') {
            return Response::json(['error' => 'Missing id'], 422);
        }

        $deleted = $this->content->delete($collection, $id);
        $this->cache->clear();

        return Response::json(['ok' => $deleted]);
    }

    private function formSubmissions(Request $request): Response
    {
        $name = (string) $request->input('name', 'contact');
        $file = $this->root . '/content/forms-submissions/' . $name . '.jsonl';
        if (!is_file($file)) {
            return Response::json(['ok' => true, 'submissions' => []]);
        }

        $rows = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return Response::json(['ok' => true, 'submissions' => array_reverse($rows)]);
    }

    private function clearCache(): Response
    {
        $this->cache->clear();
        return Response::json(['ok' => true]);
    }

    private function createBackup(): Response
    {
        $result = $this->backup->create();
        if (($result['ok'] ?? false) === true) {
            $this->security->recordAudit('backup.create', [
                'user' => $this->security->currentUser(),
                'file' => $result['file'] ?? null,
                'partial' => (bool) ($result['partial'] ?? false),
                'errors' => $result['errors'] ?? [],
                'uploads' => $result['uploads'] ?? [],
            ]);
        }

        return Response::json($result);
    }

    private function togglePlugin(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $id = (string) ($payload['id'] ?? '');
        $active = (bool) ($payload['active'] ?? false);
        if ($id === '') {
            return Response::json(['error' => 'Missing plugin id'], 422);
        }

        $this->plugins->toggle($id, $active);

        return Response::json([
            'ok' => true,
            'message' => 'Plugin state updated. Reload app to apply hook and route changes.',
        ]);
    }

    private function uploadMedia(Request $request): Response
    {
        $file = $request->files['file'] ?? null;
        if (!is_array($file)) {
            return Response::json(['error' => 'Missing file upload'], 422);
        }

        return Response::json($this->media->upload($file));
    }

    private function listMedia(Request $request): Response
    {
        $limit = (int) $request->input('limit', 200);
        return Response::json($this->media->list($limit));
    }

    private function transformMedia(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $file = trim((string) ($payload['file'] ?? ''));
        if ($file === '') {
            return Response::json(['error' => 'Missing file'], 422);
        }

        $result = $this->media->transform($file, is_array($payload) ? $payload : []);
        $status = (bool) ($result['ok'] ?? false) ? 200 : 422;
        return Response::json($result, $status);
    }

    private function settings(): Response
    {
        return Response::json([
            'ok' => true,
            'settings' => [
                'name' => Config::get($this->config, 'name', 'atoll-cms'),
                'base_url' => Config::get($this->config, 'base_url', ''),
                'updater' => Config::get($this->config, 'updater', []),
                'appearance' => Config::get($this->config, 'appearance', []),
                'smtp' => Config::get($this->config, 'smtp', []),
                'backup' => Config::get($this->config, 'backup', []),
                'security' => Config::get($this->config, 'security', []),
            ],
        ]);
    }

    private function saveSettings(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $settings = $payload['settings'] ?? null;

        if (!is_array($settings)) {
            return Response::json(['error' => 'settings must be object'], 422);
        }

        $previousTheme = (string) Config::get($this->config, 'appearance.theme', 'default');

        foreach (['name', 'base_url', 'updater', 'appearance', 'smtp', 'backup', 'security'] as $key) {
            if (array_key_exists($key, $settings)) {
                $this->config[$key] = $settings[$key];
            }
        }

        Config::save($this->configPath, $this->config);
        $currentTheme = (string) Config::get($this->config, 'appearance.theme', 'default');
        if ($currentTheme !== $previousTheme) {
            $this->cache->clear();
        }

        return Response::json(['ok' => true]);
    }

    private function pluginRegistry(): Response
    {
        $file = $this->root . '/content/data/plugin-registry.json';
        $registry = PackageInstaller::loadRegistry($file);
        $licenses = PackageInstaller::loadLicenses($this->root);
        $pluginState = [];
        foreach ($this->plugins->list() as $plugin) {
            $pluginId = (string) ($plugin['id'] ?? '');
            if ($pluginId === '') {
                continue;
            }
            $pluginState[$pluginId] = [
                'installed' => true,
                'active' => (bool) ($plugin['active'] ?? false),
            ];
        }

        foreach ($registry as &$entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $entry['installed'] = (bool) ($pluginState[$id]['installed'] ?? false);
            $entry['active'] = (bool) ($pluginState[$id]['active'] ?? false);
            $entry['has_license'] = trim((string) ($licenses['plugins'][$id] ?? '')) !== '';
        }
        unset($entry);

        return Response::json([
            'ok' => true,
            'registry' => $registry,
        ]);
    }

    private function themeRegistry(): Response
    {
        $file = $this->root . '/content/data/theme-registry.json';
        $registry = PackageInstaller::loadRegistry($file);
        $licenses = PackageInstaller::loadLicenses($this->root);
        $themes = $this->availableThemes();

        foreach ($registry as &$entry) {
            $id = (string) ($entry['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $entry['installed'] = isset($themes[$id]);
            $entry['active'] = (bool) ($themes[$id]['active'] ?? false);
            $entry['has_license'] = trim((string) ($licenses['themes'][$id] ?? '')) !== '';
        }
        unset($entry);

        return Response::json([
            'ok' => true,
            'registry' => $registry,
        ]);
    }

    private function installPlugin(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $source = trim((string) ($payload['source'] ?? ''));
        $id = trim((string) ($payload['id'] ?? ''));
        $force = (bool) ($payload['force'] ?? false);
        $enable = (bool) ($payload['enable'] ?? true);
        $licenseKey = trim((string) ($payload['license_key'] ?? ''));

        try {
            $result = $id !== ''
                ? PackageInstaller::installPluginFromRegistry(
                    $this->root,
                    $id,
                    $force,
                    $enable,
                    $this->config,
                    $licenseKey !== '' ? $licenseKey : null
                )
                : PackageInstaller::installPlugin($this->root, $source, $force, $enable, $this->config);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        $this->security->recordAudit('plugin.install', [
            'user' => $this->security->currentUser(),
            'plugin' => $result['id'] ?? $id,
        ]);

        return Response::json([
            'ok' => true,
            'installed' => $result,
            'message' => 'Plugin installed. Reload app to apply hook and route changes.',
        ]);
    }

    private function themes(): Response
    {
        $activeTheme = (string) Config::get($this->config, 'appearance.theme', 'default');
        $rows = $this->availableThemes();
        foreach ($rows as &$row) {
            $row['active'] = ($row['id'] ?? '') === $activeTheme;
        }
        unset($row);

        return Response::json([
            'ok' => true,
            'themes' => array_values($rows),
            'active' => $activeTheme,
        ]);
    }

    private function installTheme(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $source = trim((string) ($payload['source'] ?? ''));
        $id = trim((string) ($payload['id'] ?? ''));
        $force = (bool) ($payload['force'] ?? false);
        $licenseKey = trim((string) ($payload['license_key'] ?? ''));

        try {
            $result = $id !== ''
                ? PackageInstaller::installThemeFromRegistry(
                    $this->root,
                    $id,
                    $force,
                    $this->config,
                    $licenseKey !== '' ? $licenseKey : null
                )
                : PackageInstaller::installTheme($this->root, $source, $force, $this->config);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        $this->security->recordAudit('theme.install', [
            'user' => $this->security->currentUser(),
            'theme' => $result['id'] ?? $id,
        ]);

        return Response::json(['ok' => true, 'installed' => $result]);
    }

    private function activateTheme(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            return Response::json(['error' => 'Missing theme id'], 422);
        }

        $themes = $this->availableThemes();
        if (!isset($themes[$id])) {
            return Response::json(['error' => 'Theme not found: ' . $id], 404);
        }

        $appearance = Config::get($this->config, 'appearance', []);
        $appearance = is_array($appearance) ? $appearance : [];
        $appearance['theme'] = $id;
        $this->config['appearance'] = $appearance;
        Config::save($this->configPath, $this->config);
        $this->cache->clear();

        $this->security->recordAudit('theme.activate', [
            'user' => $this->security->currentUser(),
            'theme' => $id,
        ]);

        return Response::json(['ok' => true, 'active' => $id]);
    }

    private function uninstallTheme(Request $request): Response
    {
        $payload = $request->isJson() ? $request->json() : $request->post;
        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            return Response::json(['error' => 'Missing theme id'], 422);
        }

        $themes = $this->availableThemes();
        if (!isset($themes[$id])) {
            return Response::json(['error' => 'Theme not found: ' . $id], 404);
        }

        $activeTheme = (string) Config::get($this->config, 'appearance.theme', 'default');
        if ($activeTheme === $id) {
            return Response::json(['error' => 'Active theme cannot be uninstalled'], 422);
        }

        $source = (string) ($themes[$id]['source'] ?? 'site');
        if ($source !== 'site') {
            return Response::json(['error' => 'Built-in core themes cannot be uninstalled'], 422);
        }

        $themePath = rtrim($this->root, '/') . '/themes/' . $id;
        if (!is_dir($themePath) && !is_link($themePath)) {
            return Response::json(['error' => 'Theme files missing: ' . $id], 404);
        }

        try {
            $this->assertThemePathInsideSiteThemes($themePath);
            $this->deleteFilesystemPath($themePath);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        $this->cache->clear();
        $this->security->recordAudit('theme.uninstall', [
            'user' => $this->security->currentUser(),
            'theme' => $id,
        ]);

        return Response::json(['ok' => true, 'removed' => $id]);
    }

    /**
     * @return array<string, array{id:string,source:string,preview?:string,active?:bool}>
     */
    private function availableThemes(): array
    {
        $rows = [];

        $siteDirs = glob($this->root . '/themes/*', GLOB_ONLYDIR) ?: [];
        foreach ($siteDirs as $dir) {
            $id = basename($dir);
            $rows[$id] = [
                'id' => $id,
                'source' => 'site',
                'preview' => $this->resolveThemePreview($dir, $id, 'site'),
            ];
        }

        $coreDirs = glob($this->coreThemesDir() . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($coreDirs as $dir) {
            $id = basename($dir);
            if (!isset($rows[$id])) {
                $rows[$id] = [
                    'id' => $id,
                    'source' => 'core',
                    'preview' => $this->resolveThemePreview($dir, $id, 'core'),
                ];
            }
        }

        ksort($rows);
        return $rows;
    }

    private function coreThemesDir(): string
    {
        $configured = Config::get($this->config, 'core.path', 'core');
        $corePath = is_string($configured) && $configured !== '' ? $configured : 'core';
        if (!str_starts_with($corePath, '/')) {
            $corePath = $this->root . '/' . ltrim($corePath, '/');
        }

        return rtrim($corePath, '/') . '/themes';
    }

    private function resolveThemePreview(string $themeDir, string $themeId, string $source): ?string
    {
        $metaFile = rtrim($themeDir, '/') . '/theme.yaml';
        if (is_file($metaFile)) {
            $meta = Yaml::parse((string) file_get_contents($metaFile));
            $configured = (string) ($meta['preview'] ?? $meta['screenshot'] ?? $meta['thumbnail'] ?? '');
            $configured = ltrim(trim($configured), '/');
            if ($configured !== '' && is_file($themeDir . '/' . $configured)) {
                $prefix = $source === 'core' ? '/core/themes/' : '/themes/';
                return $prefix . rawurlencode($themeId) . '/' . str_replace('\\', '/', $configured);
            }
        }

        $candidates = [
            'assets/preview.webp',
            'assets/preview.png',
            'assets/preview.jpg',
            'assets/screenshot.webp',
            'assets/screenshot.png',
            'assets/screenshot.jpg',
            'assets/thumbnail.webp',
            'assets/thumbnail.png',
            'assets/thumbnail.jpg',
        ];

        foreach ($candidates as $relative) {
            if (is_file($themeDir . '/' . $relative)) {
                $prefix = $source === 'core' ? '/core/themes/' : '/themes/';
                return $prefix . rawurlencode($themeId) . '/' . $relative;
            }
        }

        return null;
    }

    private function assertThemePathInsideSiteThemes(string $themePath): void
    {
        $themesRoot = realpath(rtrim($this->root, '/') . '/themes');
        $parent = realpath(dirname($themePath));
        if ($themesRoot === false || $parent === false || $parent !== $themesRoot) {
            throw new RuntimeException('Invalid theme path.');
        }
    }

    private function deleteFilesystemPath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!@unlink($path)) {
                throw new RuntimeException('Unable to remove file: ' . basename($path));
            }
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            if (is_link($child) || is_file($child)) {
                if (!@unlink($child)) {
                    throw new RuntimeException('Unable to remove file: ' . $entry);
                }
                continue;
            }

            if (is_dir($child)) {
                $this->deleteFilesystemPath($child);
            }
        }

        if (!@rmdir($path)) {
            throw new RuntimeException('Unable to remove theme directory: ' . basename($path));
        }
    }

    private function securityAudit(Request $request): Response
    {
        $limit = (int) $request->input('limit', 100);
        $limit = max(1, min(500, $limit));

        return Response::json([
            'ok' => true,
            'entries' => $this->security->auditEntries($limit),
        ]);
    }

    private function setupTwoFactor(Request $request): Response
    {
        $currentUser = $this->security->currentUser();
        if ($currentUser === null) {
            return Response::json(['error' => 'Unauthenticated'], 401);
        }

        $payload = $request->isJson() ? $request->json() : $request->post;
        $secret = strtoupper(trim((string) ($payload['secret'] ?? '')));
        $code = trim((string) ($payload['code'] ?? ''));
        if ($secret === '') {
            $secret = $this->security->generateTotpSecret();
        }

        $issuer = (string) Config::get($this->config, 'name', 'atoll-cms');
        $uri = $this->security->totpProvisioningUri($issuer, $currentUser, $secret);

        if ($code === '') {
            return Response::json([
                'ok' => true,
                'pending' => true,
                'secret' => $secret,
                'otpauth' => $uri,
            ]);
        }

        if (!$this->security->verifyTotp($secret, $code)) {
            return Response::json(['error' => 'Invalid authenticator code'], 422);
        }

        $updated = $this->updateUser($currentUser, static function (array $user) use ($secret): array {
            $user['twofa_secret'] = $secret;
            return $user;
        });
        if (!$updated) {
            return Response::json(['error' => 'Could not update user settings'], 500);
        }

        $this->security->recordAudit('auth.2fa_enabled', ['user' => $currentUser]);

        return Response::json([
            'ok' => true,
            'pending' => false,
            'twofa_enabled' => true,
        ]);
    }

    private function disableTwoFactor(Request $request): Response
    {
        $currentUser = $this->security->currentUser();
        if ($currentUser === null) {
            return Response::json(['error' => 'Unauthenticated'], 401);
        }

        $updated = $this->updateUser($currentUser, static function (array $user): array {
            unset($user['twofa_secret']);
            return $user;
        });
        if (!$updated) {
            return Response::json(['error' => 'Could not update user settings'], 500);
        }

        $this->security->recordAudit('auth.2fa_disabled', ['user' => $currentUser]);
        return Response::json(['ok' => true, 'twofa_enabled' => false]);
    }

    private function isCurrentUserTwoFactorEnabled(): bool
    {
        $username = $this->security->currentUser();
        if ($username === null) {
            return false;
        }

        $users = Config::get($this->config, 'users', []);
        if (!is_array($users)) {
            return false;
        }

        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            if (($user['username'] ?? null) !== $username) {
                continue;
            }
            return is_string($user['twofa_secret'] ?? null) && $user['twofa_secret'] !== '';
        }

        return false;
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    private function updateUser(string $username, callable $mutator): bool
    {
        $users = Config::get($this->config, 'users', []);
        if (!is_array($users)) {
            return false;
        }

        $changed = false;
        foreach ($users as $idx => $user) {
            if (!is_array($user)) {
                continue;
            }
            if (($user['username'] ?? null) !== $username) {
                continue;
            }

            $users[$idx] = $mutator($user);
            $changed = true;
            break;
        }

        if (!$changed) {
            return false;
        }

        $this->config['users'] = $users;
        Config::save($this->configPath, $this->config);
        return true;
    }

    /**
     * @param array<int, array{id:string,label:string,route:string,icon:string}> $items
     * @param array<string, bool> $seen
     */
    private function appendAdminMenuItem(array &$items, array &$seen, mixed $candidate): void
    {
        if (!is_array($candidate)) {
            return;
        }

        $label = trim((string) ($candidate['label'] ?? ''));
        $route = trim((string) ($candidate['route'] ?? ''));
        if ($label === '' || $route === '') {
            return;
        }

        $id = trim((string) ($candidate['id'] ?? ''));
        if ($id === '') {
            $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $label));
            $slug = trim($slug, '-');
            $id = $slug !== '' ? $slug : ('menu-' . (count($items) + 1));
        }

        if (isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;

        $items[] = [
            'id' => $id,
            'label' => $label,
            'route' => $route,
            'icon' => trim((string) ($candidate['icon'] ?? '')),
        ];
    }

    /**
     * @param array<int, array{id:string,title:string,value:string,text:string}> $widgets
     */
    private function appendDashboardWidget(array &$widgets, mixed $candidate, int &$counter): void
    {
        if (is_string($candidate)) {
            $text = trim($candidate);
            if ($text === '') {
                return;
            }
            $counter++;
            $widgets[] = [
                'id' => 'widget-' . $counter,
                'title' => 'Plugin Widget',
                'value' => '',
                'text' => $text,
            ];
            return;
        }

        if (!is_array($candidate)) {
            return;
        }

        $title = trim((string) ($candidate['title'] ?? $candidate['label'] ?? ''));
        $value = trim((string) ($candidate['value'] ?? ''));
        $text = trim((string) ($candidate['text'] ?? $candidate['description'] ?? ''));
        if ($title === '' && $value === '' && $text === '') {
            return;
        }
        if ($title === '') {
            $title = 'Plugin Widget';
        }

        $id = trim((string) ($candidate['id'] ?? ''));
        if ($id === '') {
            $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $title));
            $slug = trim($slug, '-');
            $counter++;
            $id = $slug !== '' ? $slug : ('widget-' . $counter);
        }

        $widgets[] = [
            'id' => $id,
            'title' => $title,
            'value' => $value,
            'text' => $text,
        ];
    }

    private function resolveAdminFile(string $relative): string
    {
        $relative = ltrim($relative, '/');
        foreach ($this->adminRoots as $root) {
            $candidate = rtrim($root, '/') . '/' . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return '';
    }
}
