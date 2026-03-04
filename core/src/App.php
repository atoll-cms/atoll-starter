<?php

declare(strict_types=1);

namespace Atoll;

use Atoll\Admin\AdminController;
use Atoll\Backup\BackupManager;
use Atoll\Cache\CacheManager;
use Atoll\Content\ContentRepository;
use Atoll\Form\FormManager;
use Atoll\Hooks\HookManager;
use Atoll\Http\Request;
use Atoll\Http\Response;
use Atoll\Installer\InstallerController;
use Atoll\Islands\IslandManager;
use Atoll\Mail\Mailer;
use Atoll\Media\MediaManager;
use Atoll\Plugins\PluginManager;
use Atoll\Redirect\RedirectManager;
use Atoll\Routing\Router;
use Atoll\Security\SecurityManager;
use Atoll\Seo\SeoManager;
use Atoll\Support\Config;
use Atoll\Template\TemplateEngine;

final class App
{
    /** @var array<string, mixed> */
    private array $config;
    private string $coreRoot;

    public function __construct(private readonly string $root)
    {
        date_default_timezone_set('Europe/Berlin');
        $this->config = Config::load($this->root . '/config.yaml');
        $envBaseUrl = getenv('ATOLL_BASE_URL');
        if (is_string($envBaseUrl) && trim($envBaseUrl) !== '') {
            $this->config['base_url'] = trim($envBaseUrl);
        }

        $configuredCorePath = Config::get($this->config, 'core.path');
        $this->coreRoot = is_string($configuredCorePath) && $configuredCorePath !== ''
            ? $this->normalizeCorePath($configuredCorePath)
            : $this->root . '/core';

        $timezone = Config::get($this->config, 'timezone');
        if (is_string($timezone) && $timezone !== '') {
            date_default_timezone_set($timezone);
        }
    }

    public function handle(): Response
    {
        $request = Request::fromGlobals();
        $security = new SecurityManager($this->root . '/cache/rate-limit', $this->config);
        $installer = new InstallerController($this->root, $this->root . '/config.yaml');

        if (!$this->isConfigured()) {
            if (str_starts_with($request->path, '/install')) {
                return $security->applyHeaders($installer->handle($request, false));
            }

            return $security->applyHeaders(Response::redirect('/install', 302));
        }

        if (str_starts_with($request->path, '/install')) {
            return $security->applyHeaders($installer->handle($request, true));
        }

        $hooks = new HookManager();
        $pluginManager = new PluginManager(
            pluginsDir: $this->root . '/plugins',
            stateFile: $this->root . '/content/data/plugins.yaml',
            hooks: $hooks,
            defaultsEnabled: (bool) Config::get($this->config, 'plugins.defaults_enabled', true)
        );
        $pluginManager->load();

        $cache = new CacheManager(
            cacheDir: $this->root . '/cache',
            enabled: (bool) Config::get($this->config, 'cache.enabled', true),
            hooks: $hooks,
            ttl: (int) Config::get($this->config, 'cache.ttl', 3600)
        );

        $forceHttps = $security->forceHttps($request);
        if ($forceHttps !== null) {
            return $security->applyHeaders($forceHttps);
        }

        $rateLimited = $security->enforceRateLimit($request);
        if ($rateLimited !== null) {
            return $security->applyHeaders($rateLimited);
        }

        $content = new ContentRepository($this->root . '/content', $hooks);
        $redirects = new RedirectManager($this->root . '/content/data/redirects.yaml');
        $mailer = new Mailer($this->config);
        $forms = new FormManager($this->root . '/content/forms', $this->root . '/content/forms-submissions', $mailer, $security, $hooks);
        $seo = new SeoManager($this->config);

        $activeTheme = (string) Config::get($this->config, 'appearance.theme', 'default');
        $templateRoots = array_values(array_filter([
            $this->root . '/templates',
            $this->root . '/themes/' . $activeTheme . '/templates',
            $this->coreRoot . '/themes/default/templates',
        ], static fn (string $path) => is_dir($path)));

        $islands = new IslandManager(
            manifestPaths: [
                $this->coreRoot . '/islands/manifest.json',
                $this->root . '/islands/manifest.json',
            ],
            pluginIslands: $pluginManager->islandMap(),
            loaderScript: '/core/assets/js/island-loader.js',
            basePath: $this->basePath()
        );

        $templates = new TemplateEngine(
            templatePaths: $templateRoots,
            siteRoot: $this->root,
            coreRoot: $this->coreRoot,
            activeTheme: $activeTheme,
            islands: $islands,
            hooks: $hooks,
            seo: $seo,
            config: $this->config
        );

        $media = new MediaManager($this->root . '/assets/uploads', $hooks);
        $backup = new BackupManager($this->root . '/content', $this->root . '/backups');

        $admin = new AdminController(
            root: $this->root,
            configPath: $this->root . '/config.yaml',
            config: $this->config,
            adminRoots: [
                $this->root . '/admin',
                $this->coreRoot . '/admin',
            ],
            security: $security,
            content: $content,
            cache: $cache,
            plugins: $pluginManager,
            backup: $backup,
            media: $media
        );

        if (str_starts_with($request->path, '/admin') && !$security->isAdminIpAllowed($request)) {
            $security->recordAudit('admin.access_denied_ip', [
                'ip' => $request->server['REMOTE_ADDR'] ?? 'unknown',
            ]);

            if (str_starts_with($request->path, '/admin/api')) {
                return $security->applyHeaders(Response::json(['error' => 'Admin access from this IP is not allowed'], 403));
            }

            return $security->applyHeaders(Response::html('<h1>403 Forbidden</h1><p>Admin access from this IP is not allowed.</p>', 403));
        }

        if (str_starts_with($request->path, '/admin/api')) {
            return $security->applyHeaders($admin->handleApi($request));
        }

        if ($request->path === '/admin' || $request->path === '/admin/') {
            return $security->applyHeaders($admin->serveSpa());
        }

        if (str_starts_with($request->path, '/admin/')) {
            $assetPath = substr($request->path, strlen('/admin/'));
            $assetResponse = $admin->serveAsset($assetPath);
            if ($assetResponse !== null) {
                return $security->applyHeaders($assetResponse);
            }

            return $security->applyHeaders($admin->serveSpa());
        }

        if (str_starts_with($request->path, '/forms/') && $request->method === 'POST') {
            $name = trim(substr($request->path, strlen('/forms/')), '/');
            $rateLimit = $security->enforceRateLimit($request, 'forms', 20, 60);
            if ($rateLimit !== null) {
                return $security->applyHeaders($rateLimit);
            }

            return $security->applyHeaders(Response::json($forms->submit($name, $request)));
        }

        if ($request->path === '/sitemap.xml') {
            return $security->applyHeaders(
                Response::text($seo->sitemap($content), 200)->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            );
        }

        if ($request->path === '/robots.txt') {
            return $security->applyHeaders(Response::text($seo->robots()));
        }

        $redirect = $redirects->match($request->path);
        if ($redirect !== null) {
            return $security->applyHeaders(Response::redirect($redirect['to'], $redirect['status']));
        }

        if ($pluginManager->hasRoute($request->path)) {
            $pluginResponse = $pluginManager->dispatchRoute($request);
            if ($pluginResponse !== null) {
                return $security->applyHeaders($pluginResponse);
            }
        }

        if ($request->method === 'GET') {
            $cached = $cache->get($request->path);
            if ($cached !== null) {
                return $security->applyHeaders(Response::html($cached)->withHeader('X-Atoll-Cache', 'HIT'));
            }
        }

        $router = new Router(array_map(
            static fn (string $root) => rtrim($root, '/') . '/pages',
            $templateRoots
        ));
        $resolved = $router->resolve($request->path, $content);

        if ($resolved === null) {
            $html = $templates->render('pages/404.twig', [
                'page' => [
                    'title' => 'Not Found',
                    'url' => $request->path,
                    'content' => '<p>The requested page could not be found.</p>',
                    'collection' => 'pages',
                    'slug' => '404',
                ],
                'navigation' => $content->readDataFile('navigation.yaml'),
            ]);

            $processed = $islands->process($html);
            return $security->applyHeaders(Response::html($processed, 404));
        }

        $page = $resolved['page'] ?? null;
        $payload = [
            'page' => $page ? $page->toArray() : null,
            'collection' => $resolved['collection'] ?? null,
            'items' => $resolved['items'] ?? [],
            'navigation' => $content->readDataFile('navigation.yaml'),
            'csrf_token' => $security->csrfToken(),
        ];

        $hooks->run('page:before_render', $payload, $request);
        $html = $templates->render((string) $resolved['template'], $payload);
        $html = $islands->process($html);

        foreach ($hooks->run('page:after_render', $html, $payload, $request) as $result) {
            if (is_string($result) && $result !== '') {
                $html = $result;
            }
        }

        $minified = $this->minifyHtml($html);
        if ($request->method === 'GET') {
            $cache->put($request->path, $minified, (array) ($resolved['dependencies'] ?? []));
        }

        return $security->applyHeaders(
            Response::html($minified)->withHeader('X-Atoll-Cache', 'MISS')
        );
    }

    private function minifyHtml(string $html): string
    {
        $html = preg_replace('/>\s+</', '><', $html) ?? $html;
        $html = preg_replace('/\s{2,}/', ' ', $html) ?? $html;
        return trim($html);
    }

    private function normalizeCorePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }

        return rtrim($this->root . '/' . ltrim($path, '/'), '/');
    }

    private function basePath(): string
    {
        $baseUrl = (string) Config::get($this->config, 'base_url', '');
        $path = parse_url($baseUrl, PHP_URL_PATH);
        if (!is_string($path)) {
            return '';
        }

        $trimmed = trim($path, '/');
        return $trimmed === '' ? '' : '/' . $trimmed;
    }

    private function isConfigured(): bool
    {
        if (!is_file($this->root . '/config.yaml')) {
            return false;
        }

        $users = Config::get($this->config, 'users', []);
        if (!is_array($users) || $users === []) {
            return false;
        }

        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            if (is_string($user['username'] ?? null) && is_string($user['password_hash'] ?? null) && $user['password_hash'] !== '') {
                return true;
            }
        }

        return false;
    }
}
