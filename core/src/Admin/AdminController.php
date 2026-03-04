<?php

declare(strict_types=1);

namespace Atoll\Admin;

use Atoll\Backup\BackupManager;
use Atoll\Cache\CacheManager;
use Atoll\Content\ContentRepository;
use Atoll\Http\Request;
use Atoll\Http\Response;
use Atoll\Media\MediaManager;
use Atoll\Plugins\PluginManager;
use Atoll\Security\SecurityManager;
use Atoll\Support\Config;

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
            $this->security->logout();
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
            ]),
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
            $endpoint === '/backup/create' && $request->method === 'POST' => Response::json($this->backup->create()),
            $endpoint === '/plugins' && $request->method === 'GET' => Response::json(['ok' => true, 'plugins' => $this->plugins->list()]),
            $endpoint === '/plugin-registry' && $request->method === 'GET' => $this->pluginRegistry(),
            $endpoint === '/plugins/toggle' && $request->method === 'POST' => $this->togglePlugin($request),
            $endpoint === '/media/upload' && $request->method === 'POST' => $this->uploadMedia($request),
            $endpoint === '/settings' && $request->method === 'GET' => $this->settings(),
            $endpoint === '/settings/save' && $request->method === 'POST' => $this->saveSettings($request),
            default => Response::json(['error' => 'Not found', 'endpoint' => $endpoint], 404),
        };
    }

    private function login(Request $request): Response
    {
        $rateLimit = $this->security->enforceRateLimit($request, 'admin-login', 10, 60);
        if ($rateLimit !== null) {
            return $rateLimit;
        }

        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');

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
                $this->security->login($username);
                return Response::json([
                    'ok' => true,
                    'user' => $username,
                    'csrf' => $this->security->csrfToken(),
                ]);
            }
        }

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

        $file = $this->content->save($collection, $id, $frontmatter, $markdown);
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

    private function settings(): Response
    {
        return Response::json([
            'ok' => true,
            'settings' => [
                'name' => Config::get($this->config, 'name', 'atoll-cms'),
                'base_url' => Config::get($this->config, 'base_url', ''),
                'appearance' => Config::get($this->config, 'appearance', []),
                'smtp' => Config::get($this->config, 'smtp', []),
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

        foreach (['name', 'base_url', 'appearance', 'smtp', 'security'] as $key) {
            if (array_key_exists($key, $settings)) {
                $this->config[$key] = $settings[$key];
            }
        }

        Config::save($this->configPath, $this->config);

        return Response::json(['ok' => true]);
    }

    private function pluginRegistry(): Response
    {
        $file = $this->root . '/content/data/plugin-registry.json';
        if (!is_file($file)) {
            return Response::json(['ok' => true, 'registry' => []]);
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        return Response::json([
            'ok' => true,
            'registry' => is_array($decoded) ? $decoded : [],
        ]);
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
